=== Sidrena cijena i cjenik ===
Tags: sidrena cijena, cjenik, woocommerce, hrvatska
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Sidrena cijena, najniža cijena u 30 dana i dnevna objava cjenika u CSV/XML formatu prema NN 101/2026.

== Opis ==

Plugin pokriva obje odluke Vlade iz NN 101/2026: isticanje sidrene cijene i objavu cjenika na webu. Radi s WooCommerceom, a usluge i proizvode koji se ne prodaju kroz webshop možete unijeti kao stavke cjenika.

Što radi:

* Uz cijenu prikazuje sidrenu cijenu (cijenu na dan 10. 9. 2026.). Trgovci koji su već bili obveznici stare odluke (NN 75/2025) za hranu, piće, kozmetiku i ostale kategorije iz te odluke zadržavaju 2. 5. 2025.
* Kod sniženja prikazuje najnižu cijenu u zadnjih 30 dana prije sniženja.
* Svaki dan objavljuje cjenik proizvoda i cjenik usluga u CSV i/ili XML formatu (zadano u 01:00, rok je 8:00). Naziv datoteke je prema primjeru Ministarstva, npr. prodavaonica_Ilica 150 Zagreb_P-01_104_01.10.2026_07:45.csv Objekt koji ima i proizvode i usluge objavljuje dvije datoteke s istom oznakom, a svaka ima svoj broj pohrane.
* Cjenik usluga objavljuje i nakon svake promjene cijene. Akcija na usluzi može imati datum početka i kraja, pa sama počne i završi.
* Stare objave čuva najmanje 31 dan i uz njih objavljuje javni popis (index.html i index.json).
* Ako jutarnja objava ne uspije, pokušava ponovno svaki sat. Ako cjenik ni u 7:45 nije objavljen, šalje e-mail upozorenje.
* Bilježi svaku izmjenu već upisane sidrene cijene (tko, kad, staro i novo), da se može objasniti ako netko pita.
* Provjerava mogu li programi preuzeti cjenik, jer ga Cloudflare ili sigurnosni dodaci znaju blokirati.
* Sidrene cijene možete popuniti skupno ili uvesti iz Excela (CSV). Uvozom se mogu napraviti i sve stavke cjenika odjednom.
* Sidrenu cijenu usluge možete staviti bilo gdje na stranicu kratkim kodom, i ispod cijene koja je već napisana na stranici.

== Postavljanje (20-30 minuta) ==

1. Postavke -> Općenito -> Vremenska zona: Zagreb (ne UTC+2).
2. Sidrene cijene -> Postavke: upišite oblik (za web trgovinu "webshop"), adresu i oznaku prodajnog objekta (oznaka kao na računima).
3. Samo ako ste bili obveznik stare odluke (NN 75/2025): odaberite kategorije za koje vrijedi datum 2. 5. 2025.
4. Sidrene cijene -> Alati: popunite sidrene cijene (skupno ili uvozom CSV-a).
5. Usluge bez WooCommercea unesite u Sidrene cijene -> Stavke cjenika (ručno ili uvozom CSV-a u Alatima). Na stranicu s cijenama stavite kratki kod [sidrena_cjenik vrsta="usluge"], a ako stranica već ima svoju tablicu, ispod svake cijene stavite [sidrena_cijena sifra="..." prikaz="sidrena"].
6. Sidrene cijene -> Status: kliknite "Objavi cjenik sada", pa "Provjeri dostupnost robotima".
7. Postavite vanjski okidač: na stranici Status kopirajte adresu i dodajte je na besplatni cron-job.org ili UptimeRobot (5 minuta, ne treba pristup hostingu). Bez toga WordPress noću ne radi ako nitko ne posjeti stranicu.
8. U podnožje stranice stavite poveznicu na arhivu cjenika ili kratki kod [sidrena_arhiva].

== Dobro je znati ==

* Službeni format datoteke (redoslijed stupaca, razdjelnik) ne postoji. Stupci su nazvani prema tekstu odluke. Ako Ministarstvo objavi format, prilagodit ćemo ga u novoj verziji.
* Plugin ne zna koja vam je bila cijena 10. 9. 2026. ako tada nije bio instaliran. Tu cijenu trebate upisati ili uvesti.
* PDV: sidrena cijena upisuje se isto kao redovna (s PDV-om ili bez, prema postavkama WooCommercea) i prikazuje se isto kao cijena proizvoda, uz poreznu klasu proizvoda (25 %, 13 %, 5 %, bez poreza). U CSV/XML cjeniku sve je uvijek s PDV-om. Stavke cjenika upisuju se s PDV-om. Ako trgovina kupcima prikazuje cijene bez PDV-a, stranica Status to javlja upozorenjem.
* Proizvod "na narudžbu" objavljuje se kao nedostupan, jer Ministarstvo traži da dostupno znači stvarno na zalihi.
* Podržan je jedan prodajni objekt po instalaciji.
* Ako se u trgovini napravljenoj u graditelju stranica sidrena cijena ne prikaže uz proizvod, [sidrena_cijena prikaz="sidrena"] ispiše je za trenutni proizvod, a [sidrena_cijena id="123"] ili sifra="SKU" za bilo koji Woo proizvod.
* Kratki kod [sidrena_cijena] prima id ili sifra stavke (ili Woo proizvoda) i prikaz="sve", "cijena", "sidrena" ili "najniza". S oznaka="..." mijenjate tekst ispred iznosa, a oznaka="" ostavlja samo iznos.
* Za programere: funkcije scj_stavka( ID ili šifra ) i scj_proizvod( proizvod, ID ili SKU ) vraćaju podatke za predloške, WP-CLI naredbe wp sidrena objavi, status, popuni i uvoz te filteri scj_columns, scj_row_values i scj_file_name.

== Odricanje od odgovornosti ==

Plugin je izrađen s pažnjom, prema Odlukama iz NN 101/2026 i pojašnjenjima Ministarstva gospodarstva dostupnima u trenutku izrade. Trudim se da radi u skladu s propisima, ali ne mogu to jamčiti: propisi i njihova tumačenja se mijenjaju, službena tehnička specifikacija cjenika ne postoji, a u pluginu su moguće i greške.

* Plugin nije pravni savjet. U nejasnim slučajevima obratite se Ministarstvu gospodarstva, HOK-u, računovođi ili pravniku.
* Plugin koristite na vlastitu odgovornost. Za točnost cijena, sidrenih cijena i objavljenih cjenika te za usklađenost poslovanja s propisima odgovara trgovac, odnosno pružatelj usluge.
* Autor ne odgovara za štetu, novčane kazne ni druge posljedice nastale korištenjem plugina, uključujući netočne, nepotpune, zakašnjele ili neobjavljene cijene i cjenike, u mjeri u kojoj to zakon dopušta.
* Plugin se daje besplatno i bez ikakvog jamstva (licenca GPLv2 ili novija). Uvjeti eventualne plaćene usluge postavljanja dogovaraju se posebno.
* Prikazane i objavljene cijene redovito provjeravajte, posebno nakon ažuriranja plugina, WordPressa ili WooCommercea.

== Changelog ==

= 1.0.0 =
* Prva javna verzija.
