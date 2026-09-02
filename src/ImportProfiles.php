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
        'description' => 'Még nincs implementálva — nincs mintafájl a mezők egyeztetéséhez.',
        'implemented' => false,
        'field_map'   => null,
        'default_currency' => 'HUF',
        'default_vat_rate' => '27',
    ],
];
