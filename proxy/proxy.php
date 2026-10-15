<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require 'vendor/autoload.php';

require 'config.php';

$input = json_decode(file_get_contents('php://input'), true);
$mesajUtilizator = $input['mesaj'];

$tools = [
    [
        "type" => "function",
        "function" => [
            "name" => "get_wineries_by_country",
            "description" => "Returneaza podgoriile dintr-o anumita tara din JSON-Server",
            "parameters" => [
                "type" => "object",
                "properties" => [
                    "country" => ["type" => "string", "description" => "Tara podgoriei ex: Romania, France, Italy"]
                ],
                "required" => ["country"]
            ]
        ]
    ],
    [
        "type" => "function",
        "function" => [
            "name" => "add_winery",
            "description" => "Adauga o podgorie noua in JSON-Server",
            "parameters" => [
                "type" => "object",
                "properties" => [
                    "name" => ["type" => "string"],
                    "country" => ["type" => "string"],
                    "region" => ["type" => "string"],
                    "founded" => ["type" => "integer"]
                ],
                "required" => ["name", "country", "region", "founded"]
            ]
        ]
    ],
    [
        "type" => "function",
        "function" => [
            "name" => "get_wines_by_type",
            "description" => "Returneaza vinurile dupa tip din JSON-GraphQL-Server: red, white sau rose",
            "parameters" => [
                "type" => "object",
                "properties" => [
                    "type" => ["type" => "string", "description" => "Tipul vinului: red, white sau rose"]
                ],
                "required" => ["type"]
            ]
        ]
    ],
    [
        "type" => "function",
        "function" => [
            "name" => "add_wine_graphql",
            "description" => "Adauga un vin nou in baza de date GraphQL (JSON-GraphQL-Server port 3000). Foloseste acest tool cand userul cere sa adauge un vin nou fara sa mentioneze RDF sau SPARQL.",            "parameters" => [
                "type" => "object",
                "properties" => [
                    "name" => ["type" => "string"],
                    "type" => ["type" => "string"],
                    "year" => ["type" => "integer"],
                    "price" => ["type" => "number"],
                    "wineryId" => ["type" => "integer"]
                ],
                "required" => ["name", "type", "year", "price", "wineryId"]
            ]
        ]
    ],
    [
        "type" => "function",
        "function" => [
            "name" => "sparql_get_wines_by_winery",
            "description" => "Returneaza vinurile unei podgorii din RDF4J folosind SPARQL",
            "parameters" => [
                "type" => "object",
                "properties" => [
                    "winery_id" => ["type" => "string", "description" => "ID-ul podgoriei ex: winery1, winery2, winery3, winery4, winery5"]
                ],
                "required" => ["winery_id"]
            ]
        ]
    ],
    [
        "type" => "function",
        "function" => [
            "name" => "sparql_add_wine",
            "description" => "Adauga un vin nou in baza de date RDF4J folosind SPARQL INSERT. Foloseste acest tool DOAR cand userul mentioneze explicit RDF, SPARQL sau grafexamen.",            "parameters" => [
                "type" => "object",
                "properties" => [
                    "wine_id" => ["type" => "string", "description" => "ID unic ex: wine13, wine14"],
                    "name" => ["type" => "string"],
                    "type" => ["type" => "string"],
                    "year" => ["type" => "integer"],
                    "price" => ["type" => "number"],
                    "winery_id" => ["type" => "string", "description" => "ex: winery1, winery2"]
                ],
                "required" => ["wine_id", "name", "type", "year", "price", "winery_id"]
            ]
        ]
    ]
];

$corpCerere = [
    "model" => "llama-3.3-70b-versatile",
    "messages" => [
        ["role" => "system", "content" => "Esti un asistent pentru o aplicatie de podgorii si vinuri. Cand primesti date de la un tool, afiseaza-le intotdeauna explicit si complet in raspuns, inclusiv toate campurile: nume, tara, regiune, an fondare, tip, pret etc. Nu spune doar ca ai gasit date, arata-le efectiv."],
        ["role" => "user", "content" => $mesajUtilizator]
    ],
    "tools" => $tools,
    "tool_choice" => "auto"
];

$maxRetry = 3;
$raspunsGroq = null;
for ($i = 0; $i < $maxRetry; $i++) {
    $client = new EasyRdf\Http\Client("https://api.groq.com/openai/v1/chat/completions");
    $client->setMethod('POST');
    $client->setHeaders('Content-Type', 'application/json');
    $client->setHeaders('Authorization', 'Bearer ' . $GROQ_API_KEY);
    $client->setRawData(json_encode($corpCerere));
    $raspunsGroq = json_decode($client->request()->getBody(), true);
    if (isset($raspunsGroq['choices'])) break;
    sleep(2);
}

$mesaj = $raspunsGroq['choices'][0]['message'];

if (isset($mesaj['tool_calls']) && count($mesaj['tool_calls']) > 0) {
    $toolCall = $mesaj['tool_calls'][0];
    $numeTool = $toolCall['function']['name'];
    $argumente = json_decode($toolCall['function']['arguments'], true);
    $rezultatTool = apelezaTool($numeTool, $argumente);

    $corpCerere2 = [
        "model" => "llama-3.3-70b-versatile",
        "messages" => [
            ["role" => "system", "content" => "Esti un asistent pentru o aplicatie de podgorii si vinuri. Cand primesti date de la un tool, afiseaza-le intotdeauna explicit si complet in raspuns, inclusiv toate campurile: nume, tara, regiune, an fondare, tip, pret etc. Nu spune doar ca ai gasit date, arata-le efectiv."],
            ["role" => "user", "content" => $mesajUtilizator],
            $mesaj,
            [
                "role" => "tool",
                "tool_call_id" => $toolCall['id'],
                "content" => $rezultatTool
            ]
        ],
        "tools" => $tools
    ];

    $raspunsFinal = null;
    for ($i = 0; $i < $maxRetry; $i++) {
        $client2 = new EasyRdf\Http\Client("https://api.groq.com/openai/v1/chat/completions");
        $client2->setMethod('POST');
        $client2->setHeaders('Content-Type', 'application/json');
        $client2->setHeaders('Authorization', 'Bearer ' . $GROQ_API_KEY);
        $client2->setRawData(json_encode($corpCerere2));
        $raspunsFinal = json_decode($client2->request()->getBody(), true);
        if (isset($raspunsFinal['choices'])) break;
        sleep(2);
    }

    $textFinal = $raspunsFinal['choices'][0]['message']['content'];
    echo json_encode(["raspuns" => $textFinal]);
} else {
    echo json_encode(["raspuns" => $mesaj['content']]);
}

function apelezaTool($nume, $args) {
    if ($nume === "get_wineries_by_country") {
        $client = new EasyRdf\Http\Client(
            "http://localhost:4000/winery?country=" . urlencode($args['country'])
        );
        $client->setMethod('GET');
        return $client->request()->getBody();
    }

    if ($nume === "add_winery") {
        $client = new EasyRdf\Http\Client("http://localhost:4000/winery");
        $client->setMethod('POST');
        $client->setHeaders('Content-Type', 'application/json');
        $client->setRawData(json_encode($args));
        return $client->request()->getBody();
    }

    if ($nume === "get_wines_by_type") {
        $tip = $args['type'];
        $query = '{"query":"{allWines(filter:{type:\"' . $tip . '\"}){id name type year price wineryId}}"}';
        $client = new EasyRdf\Http\Client("http://localhost:3000");
        $client->setMethod('POST');
        $client->setHeaders('Content-Type', 'application/json');
        $client->setRawData($query);
        return $client->request()->getBody();
    }

    if ($nume === "add_wine_graphql") {
        $mutation = json_encode([
            "query" => "mutation{createWine(name:\"" . $args['name'] . "\",type:\"" . $args['type'] . "\",year:" . $args['year'] . ",price:" . $args['price'] . ",wineryId:" . $args['wineryId'] . "){id name}}"
        ]);
        $client = new EasyRdf\Http\Client("http://localhost:3000");
        $client->setMethod('POST');
        $client->setHeaders('Content-Type', 'application/json');
        $client->setRawData($mutation);
        return $client->request()->getBody();
    }

    if ($nume === "sparql_get_wines_by_winery") {
        $sparql = "PREFIX schema: <http://schema.org/>
SELECT ?nume ?tip ?an ?pret WHERE {
    ?w a schema:Product ;
       schema:name ?nume ;
       schema:category ?tip ;
       schema:productionDate ?an ;
       schema:price ?pret ;
       schema:isRelatedTo schema:" . $args['winery_id'] . " .
}";
        $clientSparql = new EasyRdf\Sparql\Client(
            "http://localhost:8080/rdf4j-server/repositories/grafexamen"
        );
        $rezultat = $clientSparql->query($sparql);
        $date = [];
        foreach ($rezultat as $rand) {
            $date[] = [
                "nume" => (string)$rand->nume,
                "tip" => (string)$rand->tip,
                "an" => (string)$rand->an,
                "pret" => (string)$rand->pret
            ];
        }
        return json_encode($date);
    }

    if ($nume === "sparql_add_wine") {
        $sparql = "PREFIX schema: <http://schema.org/>
INSERT DATA {
    schema:" . $args['wine_id'] . " a schema:Product ;
        schema:name \"" . $args['name'] . "\" ;
        schema:category \"" . $args['type'] . "\" ;
        schema:productionDate \"" . $args['year'] . "\" ;
        schema:price \"" . $args['price'] . "\" ;
        schema:isRelatedTo schema:" . $args['winery_id'] . " .
}";
        $clientSparql = new EasyRdf\Sparql\Client(
            "http://localhost:8080/rdf4j-server/repositories/grafexamen/statements"
        );
        $clientSparql->update($sparql);
        return json_encode(["mesaj" => "Vin adaugat cu succes in RDF4J"]);
    }

    return json_encode(["eroare" => "Tool necunoscut"]);
}
?>