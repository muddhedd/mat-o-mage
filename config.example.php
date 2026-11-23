<?php
// ============================================================
// INSTÄLLNINGAR
// ============================================================

// ---- Databas ----
define('DB_HOST', 'localhost');
define('DB_USER', 'db_anvandare');
define('DB_PASS', 'db_losenord');
define('DB_NAME', 'db_namn');

// ---- Inloggningsläge: 'login', 'll2' eller 'demo' (se förklaring överst i common.php) ----
define('AUTH_MODE', 'login');

// ---- Inbyggd inloggning (bara AUTH_MODE 'login') ----
// Lämna LOGIN_PASSWORD_HASH tom första gången: sidan visar då en guide som
// skapar hashen åt dig. Klistra in den här och ladda om sidan.
define('LOGIN_USER', 'admin');
define('LOGIN_PASSWORD_HASH', '');

// ---- Demo (bara AUTH_MODE 'demo') ----
define('DEMO_TABLE', 'DemoMatamage');   // tabell som håller reda på besökarnas sandlådor
define('DEMO_TTL_HOURS', 24);            // sandlådor som inte använts så här länge raderas

// ---- Övrigt ----
define('SESSION_NAME', 'matamage');        // '' = PHP:s standard (krävs för 'll2')
define('SESSION_LIFETIME', 86400);                 // hur länge man är inloggad (sekunder)
define('TIMEZONE', 'Europe/Stockholm');
define('MAX_TEXT_LENGTH', 1000000);              // max tecken i en anteckning

// Länkar i menyn överst: fil => namn
const MINSIDA_TOOLS = [
    'matamage.php' => 'Mat-å-Mage',
];
