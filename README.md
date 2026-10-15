Wineries & Wines – University project for the Semantic Web course

A web app where you ask questions about wineries and wines in plain language, and an AI finds or adds the data for you.

How it works
	You type a question in the web page, for example "show me the red wines".
	A PHP server sends the question to an AI model (Groq).
	The AI decides where to look and the PHP server gets the answer from one of three databases.

Technologies
	Front-end: HTML, JavaScript
	Back-end: PHP
	AI: Groq (Llama 3.3)
	Databases: JSON-Server, GraphQL, RDF4J (with Schema.org)
