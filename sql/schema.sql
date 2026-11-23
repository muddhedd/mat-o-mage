-- ============================================================
-- Mat-å-Mage: tabeller
-- Kör i phpMyAdmin (fliken SQL) eller: mysql -u ANVÄNDARE -p DATABAS < schema.sql
-- ============================================================

-- Knapparna: handlingar (t.ex. "Oboj") och konsekvenser (t.ex. "Gasig")
CREATE TABLE IF NOT EXISTS MamKnappar (
    ID         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    UserID     INT NOT NULL,
    Typ        ENUM('handling','konsekvens') NOT NULL,
    Namn       VARCHAR(100) NOT NULL,
    Farg       VARCHAR(20)  NOT NULL DEFAULT '',
    Ordning    INT NOT NULL DEFAULT 0,
    Arkiverad  TINYINT(1) NOT NULL DEFAULT 0,       -- arkiverade syns inte, men historiken finns kvar
    Skapad     DATETIME NOT NULL,
    PRIMARY KEY (ID),
    UNIQUE KEY uniq_knapp (UserID, Typ, Namn),
    KEY idx_lista (UserID, Typ, Arkiverad, Ordning)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- Varje knapptryck
CREATE TABLE IF NOT EXISTS MamLogg (
    ID         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    UserID     INT NOT NULL,
    KnappID    INT UNSIGNED NOT NULL,
    Tid        DATETIME NOT NULL,                   -- när det hände (kan loggas i efterhand)
    Styrka     TINYINT NULL,                        -- 1 lite, 2 mycket, 3 kraftigt (bara konsekvenser)
    Kommentar  VARCHAR(255) NOT NULL DEFAULT '',
    Skapad     DATETIME NOT NULL,                   -- när det loggades
    PRIMARY KEY (ID),
    KEY idx_tid (UserID, Tid),
    KEY idx_knapp (KnappID, Tid),
    CONSTRAINT fk_mamlogg_knapp FOREIGN KEY (KnappID) REFERENCES MamKnappar (ID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;

-- Inställningar per användare
CREATE TABLE IF NOT EXISTS MamInstallningar (
    UserID          INT NOT NULL,
    NamnHandling    VARCHAR(50) NOT NULL DEFAULT 'Mat',
    NamnKonsekvens  VARCHAR(50) NOT NULL DEFAULT 'Mage',
    Fonster         INT NOT NULL DEFAULT 72,        -- timmar före/efter som analyseras
    Intervall       INT NOT NULL DEFAULT 12,        -- timmar per kolumn i analysen
    Sammanslag      INT NOT NULL DEFAULT 3,         -- loggar inom så många timmar = ett tillfälle
    DoljHandling    VARCHAR(2000) NOT NULL DEFAULT '',   -- dolda handlingar i analysen (ID-lista)
    DoljKonsekvens  VARCHAR(2000) NOT NULL DEFAULT '',   -- dolda konsekvenser i analysen (ID-lista)
    PRIMARY KEY (UserID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_swedish_ci;
