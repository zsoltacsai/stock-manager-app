<?php

/**
 * Magyar névnaptár — 1.2.0 Dashboard fejléc ("Szerda, szeptember 16. —
 * Edit"). SZÁNDÉKOSAN lokális, verziózott statikus adat (lásd a kör 9.
 * pontja: "ne legyen szükség minden betöltésnél külső HTTP-kérésre") —
 * NEM fut ellene semmilyen runtime API-hívás. Forrás: a Wikipédia magyar
 * nyelvű "Magyar névnapok listája dátum szerint" cikkének nyers
 * wikitext-je (2026-09-16-i állapot), soronként (minden sor egy nap,
 * 1-től a hónap utolsó napjáig) — NEM az összefoglalt/AI-feldolgozott
 * cikkszöveg, mert az egy ellenőrzés során bizonyítottan hibás (felcserélt)
 * sorokat adott vissza. Január 23. és 24. a forrásban is üresen szerepel
 * (nincs hivatalosan bejegyzett névnap azokra a napokra) — ezt szándékosan
 * NEM töltjük ki kitalált névvel, lásd getNameDay() docblockja.
 *
 * Szökőnap (február 29.): a forrás cikk egy archaikus, a "Mátyás
 * ugrása" nevű egyházi hagyományt ír le (a szökőnap utáni napok
 * szökőévben hátrébb tolódnak) — ezt egyetlen ma használt magyar
 * naptár/szoftver sem alkalmazza a gyakorlatban, ezért itt sem
 * alkalmazzuk. A forrás februári listája 1-28-ig szól, február 29-re
 * NINCS benne hitelesen megállapítható bejegyzés — ugyanaz az elv, mint
 * január 23-24-nél: NEM rendelünk hozzá saját döntés alapján kitalált
 * nevet (pl. "Előd"), a getNameDay(2, 29) egyszerűen null-t ad vissza.
 */
final class HungarianNameDays
{
    private const DAYS = [
        1 => [ // Január
            1 => 'Alpár, Fruzsina, Bazil', 2 => 'Ábel, Gergely, Vazul', 3 => 'Genovéva, Gyöngyvér, Benjámin, Dzsenifer',
            4 => 'Titusz, Leona, Angéla', 5 => 'Simon, Emília', 6 => 'Gáspár, Menyhért, Boldizsár',
            7 => 'Attila, Etele, Ramóna, Rajmund, Bálint', 8 => 'Gyöngyvér, Keve, Szeverin, Szörény', 9 => 'Marcell, Juliánusz',
            10 => 'Melánia, Vilmos, Vilma', 11 => 'Ágota, Honoráta', 12 => 'Ernő, Erneszta, Tatjána',
            13 => 'Veronika, Csongor, Yvett', 14 => 'Bódog, Félix', 15 => 'Lóránt, Loránd, Pál',
            16 => 'Gusztáv, Marcell', 17 => 'Antal, Antónia', 18 => 'Margit, Piroska',
            19 => 'Sára, Márta, Márió', 20 => 'Fábián, Sebestyén', 21 => 'Ágnes, Agnéta',
            22 => 'Vince, Artúr', 23 => null, 24 => null,
            25 => 'Zelma, Rajmund, Emerencia, Emese, Freja, Frej', 26 => 'Timót, Ferenc', 27 => 'Pál, Henrik',
            28 => 'Vanda, Paula, Timóteusz', 29 => 'Angéla, Angelika', 30 => 'Károly, Karola, Tamás',
            31 => 'Adél, Valér',
        ],
        2 => [ // Február
            1 => 'Ignác, Brigitta, Kincső, Renátó', 2 => 'Karolina, Karola, Aida', 3 => 'Balázs, Oszkár, Celerina',
            4 => 'Ráhel, Csenge, Veronika, András', 5 => 'Ágota, Ingrid, Etelka, Léda', 6 => 'Dorottya, Dóra, Doroti, Pál',
            7 => 'Tódor, Rómeó, Richárd', 8 => 'Aranka, Jeromos', 9 => 'Abigél, Alex, Apollónia',
            10 => 'Elvira', 11 => 'Bertold, Marietta', 12 => 'Lívia, Lídia, Eulália',
            13 => 'Ella, Linda, Levente, Katalin', 14 => 'Bálint, Valentin, Cirill, Metód', 15 => 'Kolos, Györgyi, Georgina',
            16 => 'Julianna, Lilla, Filippa', 17 => 'Donát', 18 => 'Bernadett, Simon, Zenkő',
            19 => 'Zsuzsanna, Eliza, Konrád', 20 => 'Aladár, Álmos, Leó', 21 => 'Eleonóra, Zelmira, Péter',
            22 => 'Gerzson, Margit, Zétény', 23 => 'Alfréd, Polikárp, Mirtill', 24 => 'Mátyás, Jázmin',
            25 => 'Géza, Cézár, Vanda', 26 => 'Viktor, Győző, Edina', 27 => 'Ákos, Bátor, Gábor',
            28 => 'Elemér, Oszvald, Román', 29 => null,
        ],
        3 => [ // Március
            1 => 'Albin, Albina, Leonita', 2 => 'Lujza, Ágnes, Henrik, Magor', 3 => 'Kornélia, Kunigunda, Frigyes',
            4 => 'Kázmér, Lúciusz, Zorán', 5 => 'Adorján, Adrián', 6 => 'Leonóra, Inez, Koletta, Felicitász',
            7 => 'Tamás, Perpétua, Ubul', 8 => 'János, Zoltán, Apolka', 9 => 'Franciska, Fanni',
            10 => 'Ildikó, Emil, Gusztáv', 11 => 'Szilárd, Tímea, Konstantin', 12 => 'Gergely, Maximilián',
            13 => 'Krisztián, Ajtony, Egyed, Patrícia', 14 => 'Matild, Matilda, Tilla', 15 => 'Kristóf, Kelemen',
            16 => 'Henrietta, Herbert', 17 => 'Gertrúd, Patrik', 18 => 'Sándor, Ede, Cirill',
            19 => 'József, Bánk', 20 => 'Klaudia, Alexandra', 21 => 'Benedek, Bence, Miklós',
            22 => 'Beáta, Izolda, Lea', 23 => 'Emőke, Botond, Ottó, Kartal', 24 => 'Gábor, Karina',
            25 => 'Irén, Írisz, Lúcia', 26 => 'Emánuel, Emánuéla, Lara, Larissza, Árpád', 27 => 'Hajnalka, Lídia, Augusztus',
            28 => 'Gedeon, Johanna', 29 => 'Augusztus, Bercel, Bertold', 30 => 'Zalán',
            31 => 'Árpád, Benjámin, Benő',
        ],
        4 => [ // Április
            1 => 'Hugó, Agád', 2 => 'Áron, Ferenc', 3 => 'Buda, Richárd, Hóvirág, Indira',
            4 => 'Izidor', 5 => 'Vince, Irén, Teodóra', 6 => 'Vilmos, Bíborka, Taksony, Celesztin',
            7 => 'Herman, János', 8 => 'Dénes, Valér, Valter', 9 => 'Erhard, Ákos, Döme',
            10 => 'Zsolt, Ezékiel', 11 => 'Leó, Szaniszló, Glória', 12 => 'Gyula, Baldvin, Sába, Nara',
            13 => 'Ida, Márton, Hermina', 14 => 'Tibor', 15 => 'Anasztázia, Tas, Oktávia',
            16 => 'Csongor, Bernadett', 17 => 'Rudolf, Izidóra', 18 => 'Andrea, Ilma, Apolló, Aladár',
            19 => 'Emma, Malvin, Zseraldina', 20 => 'Tivadar, Tihamér, Töhötöm', 21 => 'Konrád, Zelmira, Anzelm',
            22 => 'Csilla, Noémi, Kájusz, Noé', 23 => 'Béla, Adalbert', 24 => 'György, Fidél, Debóra',
            25 => 'Márk, Ányos, Mohamed', 26 => 'Ervin, Klétusz', 27 => 'Zita, Mariann, Anasztáz',
            28 => 'Valéria, Péter', 29 => 'Péter, Katalin, Roberta', 30 => 'Katalin, Kitti, Zsófia, Piusz',
        ],
        5 => [ // Május
            1 => 'Fülöp, Jakab, Zsaklin, Jefte, József, Valburga, Fédra', 2 => 'Zsigmond, Idir, Zoé', 3 => 'Tímea, Irma, Jakab, Fülöp',
            4 => 'Mónika, Flórián', 5 => 'Györgyi, Irén', 6 => 'Ivett, Frida, Judit, Yvett',
            7 => 'Gizella, Gusztáv, Bendegúz, Gália', 8 => 'Mihály, Győző', 9 => 'Gergely, Katinka, Alberta, Édua, Mira',
            10 => 'Ármin, Pálma, Izidor', 11 => 'Ferenc, Sára', 12 => 'Pongrác',
            13 => 'Szervác, Imola, Imelda', 14 => 'Bonifác, Gyöngyi', 15 => 'Bodza, Zsófia, Szonja, Döníz',
            16 => 'Mózes, Botond, János', 17 => 'Paszkál, Ditmár, Rezeda, Lian', 18 => 'Erik, Alexandra, János, Hanga',
            19 => 'Ivó, Iván, Milán', 20 => 'Bernát, Bernardin, Felícia', 21 => 'Konstantin, András',
            22 => 'Júlia, Rita, Emil', 23 => 'Dezső, Vilmos, Renáta', 24 => 'Eszter, Eliza, Vanessza',
            25 => 'Orbán, Gergely', 26 => 'Fülöp, Evelin', 27 => 'Hella, Pelbárt, Ágoston',
            28 => 'Emil, Csanád, Vilmos', 29 => 'Magdolna, Magda, Ervin, Léna', 30 => 'Janka, Zsanett, Johanna, Nándor',
            31 => 'Angéla, Petronella',
        ],
        6 => [ // Június
            1 => 'Tünde, Jusztinusz', 2 => 'Kármen, Anita, Péter, Marcellinusz', 3 => 'Klotild, Cecília, Károly, Kevin',
            4 => 'Bulcsú, Kerény, Kerubin', 5 => 'Frézia, Zenke, Fatime, Fatima, Bonifác', 6 => 'Norbert, Norberta, Cintia',
            7 => 'Róbert, Robertina, Arianna, Fülöp, Roberta', 8 => 'Medárd, Helga', 9 => 'Félix, Előd, Annamária, Annabella',
            10 => 'Margit, Gréta', 11 => 'Barnabás, Barangó', 12 => 'Villő, Orfeusz, Adelaida, Duru',
            13 => 'Antal, Anett', 14 => 'Vazul, Elizeus, Herta', 15 => 'Jolán, Vid, Viola, Ariana',
            16 => 'Jusztin, Jusztina, Auréliusz', 17 => 'Laura, Alida, Alina, Szabolcs, Adolf, Bató', 18 => 'Arnold, Levente, Doloróza',
            19 => 'Gyárfás, Romuald, Azurea, Zorka', 20 => 'Rafael, Dina', 21 => 'Alajos, Leila',
            22 => 'Paulina, Tamás', 23 => 'Zoltán, Szultána', 24 => 'János, Iván',
            25 => 'Vilmos, Viola, Vilma', 26 => 'János, Pál, Cirill', 27 => 'László, Sámson',
            28 => 'Levente, Irén, Iréneusz', 29 => 'Péter, Pál, Adeliz, Adeliza, Emőke, Judit, Petra, Szulamit, Ivett', 30 => 'Pál',
        ],
        7 => [ // Július
            1 => 'Tihamér, Annamária, Olivér, Áron', 2 => 'Ottó', 3 => 'Kornél, Soma, Tamás',
            4 => 'Ulrik, Erzsébet, Fédra, Babett', 5 => 'Emese, Sarolta, Lotti, Antal, Nara, Anton', 6 => 'Csaba, Mária',
            7 => 'Apollónia, Vilibald, Bene', 8 => 'Ellák, Edgár, Eperke, Zsóka', 9 => 'Lukrécia, Veronika, Hajnalka',
            10 => 'Amália, Melina, Engelbert, Ulrika', 11 => 'Nóra, Lili, Nelli, Benedek', 12 => 'Izabella, Dalma, Eleonóra',
            13 => 'Jenő, Henrik', 14 => 'Örs, Stella, Kamil', 15 => 'Örkény, Henrik, Roland, Bonaventúra, Csegő',
            16 => 'Valter, Irma', 17 => 'Endre, Elek, András', 18 => 'Szömér, Frigyes, Milla, Hedvig, Mirkó, Federikó',
            19 => 'Emília', 20 => 'Illés, Margaréta', 21 => 'Dániel, Daniella, Lőrinc',
            22 => 'Magdolna, Mária, Magda, Nara, Léna', 23 => 'Lenke, Brigitta, Apollinár', 24 => 'Kinga, Kunigunda, Kincső, Krisztina',
            25 => 'Kristóf, Jakab', 26 => 'Panna, Anna, Anikó, Joakim', 27 => 'Olga, Liliána, Natália, Pantaleon',
            28 => 'Szabolcs, Alina, Ince, Győző', 29 => 'Márta, Flóra', 30 => 'Judit, Xénia, Péter',
            31 => 'Oszkár, Ignác, Bató',
        ],
        8 => [ // Augusztus
            1 => 'Boglárka, Nimród, Alfonz', 2 => 'Lehel', 3 => 'Hermina, Lídia, Kamélia, Kíra, Mirtill',
            4 => 'Domonkos, Dominik, János, Dominika', 5 => 'Krisztina', 6 => 'Berta, Bettina',
            7 => 'Ibolya', 8 => 'László, Domonkos', 9 => 'Emőd, Román',
            10 => 'Lőrinc, Blanka, Csilla', 11 => 'Zsuzsanna, Tiborc, Klára', 12 => 'Klára, Hilária, Diána',
            13 => 'Ipoly, Ince, Vitália', 14 => 'Marcell, Maximilián', 15 => 'Mária',
            16 => 'Ábrahám, Rókus', 17 => 'Jácint, Réka, Hetény', 18 => 'Ilona, Rajnald',
            19 => 'Huba, Marián, Emília', 20 => 'István, Bernát', 21 => 'Sámuel, Hajna, Piusz',
            22 => 'Menyhért, Mirjam, Merse', 23 => 'Bence, Róza, Szidónia', 24 => 'Bertalan, Aliz, Detre',
            25 => 'Lajos, Patrícia', 26 => 'Izsó, Tália, Natália, Zamfira', 27 => 'Gáspár, Mónika',
            28 => 'Ágoston, Mózes', 29 => 'Beatrix, Erna', 30 => 'Rózsa, Róza, Félix, Letícia',
            31 => 'Erika, Bella, Arisztid, Hanga, Amina',
        ],
        9 => [ // Szeptember
            1 => 'Egyed, Egon, Noémi, Tamara', 2 => 'Rebeka, Dorina, Renáta, Ingrid, István, Axel, Fédra', 3 => 'Hilda, Gergely',
            4 => 'Rozália, Róza, Ida', 5 => 'Viktor, Lőrinc, Ofélia', 6 => 'Zakariás, Beáta, Brájen',
            7 => 'Regina', 8 => 'Mária, Adrienn', 9 => 'Ádám, Péter',
            10 => 'Nikolett, Hunor, Miklós', 11 => 'Teodóra, Jácint, Igor, Helga', 12 => 'Mária, Irma',
            13 => 'Kornél, János', 14 => 'Szeréna, Roxána', 15 => 'Enikő, Melitta',
            16 => 'Edit, Ciprián', 17 => 'Zsófia, Róbert', 18 => 'Diána, József',
            19 => 'Vilhelmina, Januáriusz, Dorián', 20 => 'Friderika', 21 => 'Máté, Mirella, Jónás',
            22 => 'Móric, Tamás', 23 => 'Tekla, Líviusz, Ila, Nara', 24 => 'Gellért, Gerda, Mercédesz, Dodo',
            25 => 'Eufrozina, Kende', 26 => 'Jusztina, Kozma, Damján', 27 => 'Adalbert, Vince',
            28 => 'Vencel, Salamon', 29 => 'Mihály, Gábor, Rafael, Mirabella', 30 => 'Jeromos, Honória, Hunor',
        ],
        10 => [ // Október
            1 => 'Malvin, Teréz', 2 => 'Petra, Örs', 3 => 'Helga, Évald',
            4 => 'Ferenc, Hajnalka, Zorka', 5 => 'Aurél, Placid, Attila', 6 => 'Brúnó, Renáta, Renátó',
            7 => 'Amália, Bekény', 8 => 'Koppány, Benedikta', 9 => 'Dénes, János',
            10 => 'Gedeon, Ferenc, Bendegúz', 11 => 'Brigitta, Placida, Etel, Gitta', 12 => 'Miksa, Rezső, Edvin',
            13 => 'Kálmán, Ede, Edvárd', 14 => 'Helén, Kalixtusz', 15 => 'Teréz, Aranka',
            16 => 'Gál, Margit, Hedvig', 17 => 'Hedvig, Ignác, Rudolf', 18 => 'Lukács, Jusztusz',
            19 => 'Nándor, János, Pál', 20 => 'Vendel, Irén, Kleopátra', 21 => 'Orsolya, Zsolt',
            22 => 'Előd, Szalóme, Kordélia', 23 => 'Gyöngyvér, János, Gyöngyi', 24 => 'Salamon, Antal',
            25 => 'Blanka, Bianka, Dália, Beniel, Mór', 26 => 'Dömötör, Armand, Örs', 27 => 'Szabina, Antonietta',
            28 => 'Simon, Szimonetta, Szimóna, Júdás, Tádé', 29 => 'Nárcisz, Melinda, Őzike', 30 => 'Alfonz, Zenóbia',
            31 => 'Farkas, Rodrigó',
        ],
        11 => [ // November
            1 => 'Marianna', 2 => 'Achilles, Bató', 3 => 'Győző, Márton',
            4 => 'Károly, Karola', 5 => 'Imre, Zakariás, Tétény', 6 => 'Lénárd',
            7 => 'Csenger, Rezső, Ernő, Florentin', 8 => 'Zsombor, Kolos, Gottfrid', 9 => 'Tivadar',
            10 => 'Réka, András, Leó', 11 => 'Márton, Atád, Tódor', 12 => 'Jónás, Renátó, Jozafát',
            13 => 'Szilvia, Szaniszló', 14 => 'Aliz, Vanda, Huba, Klementina', 15 => 'Albert, Lipót',
            16 => 'Ödön, Margit', 17 => 'Hortenzia, Gergő, Dénes', 18 => 'Jenő, Noé',
            19 => 'Erzsébet', 20 => 'Jolán, Zsolt, Ödön, Bódog', 21 => 'Olivér',
            22 => 'Cecília, Filemon', 23 => 'Kelemen, Klementina, Kolumbán', 24 => 'Emma, Flóra, Virág, Emmaróza',
            25 => 'Katalin, Liza, Katinka', 26 => 'Virág, Szvetlana, Konrád, Viktória, Milos', 27 => 'Virgil, Virgínia',
            28 => 'Stefánia, Jakab', 29 => 'Taksony, Ilma, Filoména', 30 => 'András, Andor, Andrea',
        ],
        12 => [ // December
            1 => 'Elza, Blanka, Bonita', 2 => 'Melinda, Vivien, Aranka', 3 => 'Ferenc, Olívia',
            4 => 'Borbála, Barbara, János', 5 => 'Vilma, Ünige, Csaba', 6 => 'Miklós, Csinszka, Gyopár, Gyopárka',
            7 => 'Ambrus, Ambrózia', 8 => 'Mária, Emőke', 9 => 'Natália, Valéria, Filótea',
            10 => 'Judit, Loretta, Eulália', 11 => 'Árpád, Árpádina, Damazusz', 12 => 'Gabriella, Johanna, Franciska',
            13 => 'Luca, Otília, Lúcia, Éda, Tilia', 14 => 'Szilárda, Szilárd, János', 15 => 'Valér, Detre',
            16 => 'Etelka, Aletta, Adelaida', 17 => 'Lázár, Olimpia', 18 => 'Auguszta, Gracián',
            19 => 'Viola, Anasztáz', 20 => 'Teofil, Liberátusz', 21 => 'Tamás, Péter',
            22 => 'Zénó, Flórián', 23 => 'Viktória, János', 24 => 'Ádám, Éva, Adél, Noé',
            25 => 'Eugénia, Anasztázia, Noel', 26 => 'István', 27 => 'János, Teodor',
            28 => 'Kamilla, Apor', 29 => 'Tamás, Tamara', 30 => 'Dávid, Hunor, Libériusz',
            31 => 'Szilveszter, Donáta',
        ],
    ];

    private const WEEKDAYS = ['vasárnap', 'hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat'];
    private const MONTHS = [
        1 => 'január', 2 => 'február', 3 => 'március', 4 => 'április', 5 => 'május', 6 => 'június',
        7 => 'július', 8 => 'augusztus', 9 => 'szeptember', 10 => 'október', 11 => 'november', 12 => 'december',
    ];

    /**
     * @return string|null null, ha erre a napra nincs bejegyzett névnap a
     *         forrásban (jelenleg csak január 23-24.) — a hívó ilyenkor
     *         NE jelenítsen meg semmit, sose találjon ki egy nevet.
     */
    public static function getNameDay(int $month, int $day): ?string
    {
        return self::DAYS[$month][$day] ?? null;
    }

    /** Magyar formátumú dátum, pl. "Szerda, szeptember 16." — a $timestamp a hívó által már a helyes (Europe/Budapest) időzónában értelmezett időpont. */
    public static function formatHungarianDate(int $timestamp): string
    {
        $weekday = self::WEEKDAYS[(int) date('w', $timestamp)];
        $weekday = mb_strtoupper(mb_substr($weekday, 0, 1)) . mb_substr($weekday, 1);
        $month = self::MONTHS[(int) date('n', $timestamp)];
        $day = (int) date('j', $timestamp);
        return "$weekday, $month $day.";
    }
}
