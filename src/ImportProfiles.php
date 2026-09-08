<?php

return [
    'axel_pro' => [
        'label'       => 'Axel Pro',
        'description' => 'Axel Pro "Árucikk lista" exportált CSV fájlja',
        'implemented' => true,

        'field_map' => [
            'name'               => 'Megnevezés',
            'cikkszam'           => 'Cikkszám',
            'group_name'         => 'Csoport',
            'stock_qty'          => 'Készlet',
            'unit'               => 'Mértékegység',
            'purchase_price_net' => 'Nettó Beszerzési ár',
            'net_price'          => 'Nettó Eladási ár',
            'price'              => 'Bruttó Eladási ár',
            'barcode'            => 'Vonalkód',
            'notes'              => 'Egyéb',
        ],

        'default_currency' => 'HUF',
        'default_vat_rate' => '27',
    ],

    'jutasoft' => [
        'label'       => 'Jutasoft',
        'description' => 'Jutasoft készletkezelő "Raktárkészlet nyomtatás" exportált .xlsx fájlja',
        'implemented' => true,

        // A Jutasoft-riport a tényleges oszlopfejléc (Vonalkód/Megnevezés/
        // Típus/...) ELŐTT 6 sornyi riport-metaadatot ad ki (cím, szűrési
        // feltételek, nyomtatás dátuma) — ezt kell átugrani, mielőtt a
        // 7. sor fejlécként beolvasásra kerülne. Lásd CsvImporter::readRows().
        'skip_lines' => 6,

        // A riport a termék-sorok UTÁN néhány tucat összesítő/ÁFA-bontás
        // sorral zárul (pl. "Eladási érték 27%-os ÁFA kulcs: ..."), amiknek
        // NINCS vonalkóduk/kódjuk, csak egy összesítő szöveg a "Megnevezés"
        // oszlopban — emiatt az üres-név-ellenőrzés önmagában nem szűrné ki
        // őket. Ez a jelző azt mondja az import-preview.php/import-commit.php-
        // nak, hogy vonalkód ÉS cikkszám hiányában is hagyja ki a sort, még
        // ha van is "neve" (ténylegesen egy összesítő felirat).
        'skip_rows_without_identifier' => true,

        'field_map' => [
            'name'               => 'Megnevezés',
            'barcode'            => 'Vonalkód',
            'cikkszam'           => 'Kód',
            'group_name'         => 'Típus',
            'stock_qty'          => 'Készlet',
            'unit'               => 'M.Egys',
            'purchase_price_net' => 'Besz.ár',
            'price'              => 'Egys.ár',
            'vat_rate'           => 'ÁFA%',
        ],

        'default_currency' => 'HUF',
        'default_vat_rate' => '27',
    ],
];
