-- =====================================================================
-- Ola flora — données de départ (seed), spécification §4.
-- Idempotent : INSERT IGNORE + identifiants explicites (et clés UNIQUE
-- naturelles) — une seconde exécution ne modifie rien.
-- Un seul tenant (id 1, « Ola flora »), une marque (id 1, « ola flora »).
-- Aucune donnée personnelle : NIP, adresse, téléphones, e-mails, compte
-- bancaire, identifiant Tpay laissés vides.
-- Décisions client appliquées (voir SCHEMA.md) :
--   1. une seule chaîne de 7 étapes (of_stages, modèle §2.3) ;
--   2. offer_validity_days = 30 ;
--   3. 3 tranches 30 / 35 / 35 (deposit_pct = 30) ;
--   4. pakiet bazowy = somme des positions = 267 500 gr ;
--   5. structure multi-tenant conservée, tenant unique.
-- Ordre d'insertion = ordre des dépendances (FOREIGN_KEY_CHECKS reste à 1).
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_polish_ci;

-- ---------------------------------------------------------------------
-- 4.15 Plateforme (super admin)
-- ---------------------------------------------------------------------

INSERT IGNORE INTO of_plans
  (id, code, name, tag, price_month_gr, yearly_discount_pct, max_brands, max_shops,
   has_rental_module, has_roles, has_custom_domain, features_json, sort, active)
VALUES
(1, 'pracownia', 'Pracownia', 'wejście', 12900, 15.00, 1, 1, 0, 0, 0,
 '["jeden sklep i jedna marka","oferty, Tpay, kalendarz","CMS strony i sezony","bez modułu wynajmu sprzętu"]', 1, 1),
(2, 'studio', 'Studio', 'najczęściej wybierany', 24900, 15.00, 3, 3, 1, 0, 0,
 '["do trzech marek","moduł wynajmu sprzętu i magazyn","kreator zapytań weselnych","umowy i PDF-y z własnym logo"]', 2, 1),
(3, 'enterprise', 'Enterprise', 'dla większych', 59000, 15.00, NULL, NULL, 1, 1, 1,
 '["bez limitu marek i sklepów","role i uprawnienia zespołu","własna domena i szablony maili","wsparcie w 24 h"]', 3, 1);

INSERT IGNORE INTO of_platform_settings (id, skey, svalue, is_secret) VALUES
(1,  'platform_name',          'Kwiatostan', 0),
(2,  'stripe_mode',            'testowy', 0),
(3,  'stripe_account_id',      NULL, 0),
(4,  'stripe_publishable_key', NULL, 0),
(5,  'stripe_secret_key',      NULL, 1),
(6,  'stripe_webhook_secret',  NULL, 1),
(7,  'stripe_connected',       '0', 0),
(8,  'trial_days',             '14', 0),
(9,  'dormant_after_days',     '12', 0),
(10, 'billing_cycles_json',    '[{"code":"mies","label":"miesięcznie"},{"code":"rok","label":"rocznie (−15%)"}]', 0);

-- ---------------------------------------------------------------------
-- Tenant et marque
-- ---------------------------------------------------------------------

INSERT IGNORE INTO of_tenants
  (id, name, domain, city, color_hex, owner_email, plan_code, billing_cycle, status)
VALUES
(1, 'Ola flora', 'olaflora.pl', NULL, '#cdcb9a', '', 'studio', 'mies', 'aktywny');

-- 4.1 Bibliothèque photo du catalogue (20, assets/photos/*.jpeg — fichiers absents du paquet)
INSERT IGNORE INTO of_media
  (id, tenant_id, kind, path, filename, mime, bytes, alt, gallery_category, display_scale, in_public_gallery, sort)
VALUES
(1,  1, 'photo', 'assets/photos/welon-ogrod.jpeg',          'welon-ogrod.jpeg',          'image/jpeg', 0, 'panna młoda w welonie, ogród', NULL, 1.00, 0, 1),
(2,  1, 'photo', 'assets/photos/ceremonia-las.jpeg',        'ceremonia-las.jpeg',        'image/jpeg', 0, 'ceremonia w lesie',            NULL, 1.00, 0, 2),
(3,  1, 'photo', 'assets/photos/oranzeria-stol.jpeg',       'oranzeria-stol.jpeg',       'image/jpeg', 0, 'stół w oranżerii',             NULL, 1.00, 0, 3),
(4,  1, 'photo', 'assets/photos/luk-wieczor.jpeg',          'luk-wieczor.jpeg',          'image/jpeg', 0, 'łuk kwiatowy wieczorem',       NULL, 1.00, 0, 4),
(5,  1, 'photo', 'assets/photos/brama-plener.jpeg',         'brama-plener.jpeg',         'image/jpeg', 0, 'brama kwiatowa w plenerze',    NULL, 1.00, 0, 5),
(6,  1, 'photo', 'assets/photos/scianka-wstazki.jpeg',      'scianka-wstazki.jpeg',      'image/jpeg', 0, 'ścianka ze wstążkami',         NULL, 1.00, 0, 6),
(7,  1, 'photo', 'assets/photos/panna-mloda-wstazki.jpeg',  'panna-mloda-wstazki.jpeg',  'image/jpeg', 0, 'panna młoda ze wstążkami',     NULL, 1.00, 0, 7),
(8,  1, 'photo', 'assets/photos/bukiet-pastelowy.jpeg',     'bukiet-pastelowy.jpeg',     'image/jpeg', 0, 'bukiet pastelowy',             NULL, 1.00, 0, 8),
(9,  1, 'photo', 'assets/photos/bukiet-kolorowy.jpeg',      'bukiet-kolorowy.jpeg',      'image/jpeg', 0, 'bukiet kolorowy',              NULL, 1.00, 0, 9),
(10, 1, 'photo', 'assets/photos/bukiet-jezioro.jpeg',       'bukiet-jezioro.jpeg',       'image/jpeg', 0, 'bukiet nad jeziorem',          NULL, 1.00, 0, 10),
(11, 1, 'photo', 'assets/photos/kalie-suknia.jpeg',         'kalie-suknia.jpeg',         'image/jpeg', 0, 'kalie i suknia',               NULL, 1.00, 0, 11),
(12, 1, 'photo', 'assets/photos/kalie-profil.jpeg',         'kalie-profil.jpeg',         'image/jpeg', 0, 'kalie, profil',                NULL, 1.00, 0, 12),
(13, 1, 'photo', 'assets/photos/kalie-czarnobiale.jpeg',    'kalie-czarnobiale.jpeg',    'image/jpeg', 0, 'kalie, czarno-białe',          NULL, 1.00, 0, 13),
(14, 1, 'photo', 'assets/photos/stol-gosciny.jpeg',         'stol-gosciny.jpeg',         'image/jpeg', 0, 'stół gościnny',                NULL, 1.00, 0, 14),
(15, 1, 'photo', 'assets/photos/stol-prezydialny.jpeg',     'stol-prezydialny.jpeg',     'image/jpeg', 0, 'stół prezydialny',             NULL, 1.00, 0, 15),
(16, 1, 'photo', 'assets/photos/kosciol-taniec.jpeg',       'kosciol-taniec.jpeg',       'image/jpeg', 0, 'kościół, pierwszy taniec',     NULL, 1.00, 0, 16),
(17, 1, 'photo', 'assets/photos/para-pole.jpeg',            'para-pole.jpeg',            'image/jpeg', 0, 'para w polu',                  NULL, 1.00, 0, 17),
(18, 1, 'photo', 'assets/photos/para-plot.jpeg',            'para-plot.jpeg',            'image/jpeg', 0, 'para przy płocie',             NULL, 1.00, 0, 18),
(19, 1, 'photo', 'assets/photos/para-wachlarz.jpeg',        'para-wachlarz.jpeg',        'image/jpeg', 0, 'para z wachlarzem',            NULL, 1.00, 0, 19),
(20, 1, 'photo', 'assets/photos/detale-buty.jpeg',          'detale-buty.jpeg',          'image/jpeg', 0, 'detale, buty',                 NULL, 1.00, 0, 20);

-- 4.14 Galerie de la page publique (75 photos, in_public_gallery = 1, ids 101–175 = 100 + sort)
INSERT IGNORE INTO of_media
  (id, tenant_id, kind, path, filename, mime, bytes, alt, gallery_category, display_scale, in_public_gallery, sort)
VALUES
(101, 1, 'photo', 'assets/photos/ola/panna-mloda-kalie.jpg', 'panna-mloda-kalie.jpg', 'image/jpeg', 0, 'panna młoda z kaliami', 'bukiety', 1.0, 1, 1),
(102, 1, 'photo', 'assets/photos/ola/bukiet-jezioro-2.jpg', 'bukiet-jezioro-2.jpg', 'image/jpeg', 0, 'bukiet nad jeziorem', 'bukiety', 1.0, 1, 2),
(103, 1, 'photo', 'assets/photos/ola/sala-dalie-stojak.jpg', 'sala-dalie-stojak.jpg', 'image/jpeg', 0, 'dalie na wysokim złotym stojaku', 'sala', 1.0, 1, 3),
(104, 1, 'photo', 'assets/photos/ola/sala-ostrozka-biala.jpg', 'sala-ostrozka-biala.jpg', 'image/jpeg', 0, 'biała ostróżka i róże na stole', 'sala', 1.0, 1, 4),
(105, 1, 'photo', 'assets/photos/ola/bukiet-dalie.jpg', 'bukiet-dalie.jpg', 'image/jpeg', 0, 'bukiet z dalii', 'bukiety', 1.0, 1, 5),
(106, 1, 'photo', 'assets/photos/ola/sala-pampasy-plan-stolow.jpg', 'sala-pampasy-plan-stolow.jpg', 'image/jpeg', 0, 'pampasy przy planie stołów', 'sala', 1.0, 1, 6),
(107, 1, 'photo', 'assets/photos/ola/sala-biale-z-gory-2.jpg', 'sala-biale-z-gory-2.jpg', 'image/jpeg', 0, 'biała kompozycja z góry', 'sala', 1.0, 1, 7),
(108, 1, 'photo', 'assets/photos/ola/sala-biale-dalie-agenda.jpg', 'sala-biale-dalie-agenda.jpg', 'image/jpeg', 0, 'biała dalia i agenda dnia', 'sala', 1.0, 1, 8),
(109, 1, 'photo', 'assets/photos/ola/sala-biale-sala.jpg', 'sala-biale-sala.jpg', 'image/jpeg', 0, 'sala z białymi kompozycjami', 'sala', 1.0, 1, 9),
(110, 1, 'photo', 'assets/photos/ola/sala-biale-okna.jpg', 'sala-biale-okna.jpg', 'image/jpeg', 0, 'białe róże na długim stole przy oknach', 'sala', 1.0, 1, 10),
(111, 1, 'photo', 'assets/photos/ola/kwiaty-flowerbox.jpg', 'kwiaty-flowerbox.jpg', 'image/jpeg', 0, 'flowerbox z daliami i ostróżką', 'kwiaty', 1.0, 1, 11),
(112, 1, 'photo', 'assets/photos/ola/firmy-recepcja-ostrokrzew.jpg', 'firmy-recepcja-ostrokrzew.jpg', 'image/jpeg', 0, 'ostrokrzew i wstążki na recepcji', 'firmy', 1.0, 1, 12),
(113, 1, 'photo', 'assets/photos/ola/sala-bluszcz-polne.jpg', 'sala-bluszcz-polne.jpg', 'image/jpeg', 0, 'bluszcz i kwiaty polne w stodole', 'sala', 1.0, 1, 13),
(114, 1, 'photo', 'assets/photos/ola/kwiaty-bukiet-cynie.jpg', 'kwiaty-bukiet-cynie.jpg', 'image/jpeg', 0, 'bukiet z cynii i dalii', 'kwiaty', 1.0, 1, 14),
(115, 1, 'photo', 'assets/photos/ola/firmy-hortensje-instalacja.jpg', 'firmy-hortensje-instalacja.jpg', 'image/jpeg', 0, 'instalacja z suszonych hortensji', 'firmy', 1.0, 1, 15),
(116, 1, 'photo', 'assets/photos/ola/sala-pampasy-prezydialny.jpg', 'sala-pampasy-prezydialny.jpg', 'image/jpeg', 0, 'stół prezydialny z pampasami', 'sala', 1.0, 1, 16),
(117, 1, 'photo', 'assets/photos/ola/firmy-dalie-biuro.jpg', 'firmy-dalie-biuro.jpg', 'image/jpeg', 0, 'dalie w biurze', 'firmy', 1.0, 1, 17),
(118, 1, 'photo', 'assets/photos/ola/sala-biale-dalie.jpg', 'sala-biale-dalie.jpg', 'image/jpeg', 0, 'białe dalie na stole', 'sala', 1.0, 1, 18),
(119, 1, 'photo', 'assets/photos/ola/kwiaty-serce.jpg', 'kwiaty-serce.jpg', 'image/jpeg', 0, 'serce z róż i goździków', 'kwiaty', 1.0, 1, 19),
(120, 1, 'photo', 'assets/photos/ola/firmy-salon-slonecznik.jpg', 'firmy-salon-slonecznik.jpg', 'image/jpeg', 0, 'słoneczniki w salonie samochodowym', 'firmy', 1.0, 1, 20),
(121, 1, 'photo', 'assets/photos/ola/sala-polne-wazoniki.jpg', 'sala-polne-wazoniki.jpg', 'image/jpeg', 0, 'kwiaty polne w małych wazonikach', 'sala', 1.0, 1, 21),
(122, 1, 'photo', 'assets/photos/ola/firmy-slonecznik-amarantus.jpg', 'firmy-slonecznik-amarantus.jpg', 'image/jpeg', 0, 'słoneczniki i amarantus', 'firmy', 1.0, 1, 22),
(123, 1, 'photo', 'assets/photos/ola/sala-biale-kompozycje.jpg', 'sala-biale-kompozycje.jpg', 'image/jpeg', 0, 'białe kompozycje na stołach', 'sala', 1.0, 1, 23),
(124, 1, 'photo', 'assets/photos/ola/kwiaty-bukiet-cynie-2.jpg', 'kwiaty-bukiet-cynie-2.jpg', 'image/jpeg', 0, 'bukiet w różowym papierze', 'kwiaty', 1.0, 1, 24),
(125, 1, 'photo', 'assets/photos/ola/firmy-galeria-slonecznik.jpg', 'firmy-galeria-slonecznik.jpg', 'image/jpeg', 0, 'słoneczniki w galerii', 'firmy', 1.0, 1, 25),
(126, 1, 'photo', 'assets/photos/ola/sala-biale-roze-z-gory.jpg', 'sala-biale-roze-z-gory.jpg', 'image/jpeg', 0, 'białe róże na stole z góry', 'sala', 1.0, 1, 26),
(127, 1, 'photo', 'assets/photos/ola/pampasy-aleja.jpg', 'pampasy-aleja.jpg', 'image/jpeg', 0, 'aleja z pampasami w polu', 'ceremonia', 1.0, 1, 27),
(128, 1, 'photo', 'assets/photos/ola/sala-paprocie.jpg', 'sala-paprocie.jpg', 'image/jpeg', 0, 'sala z paprociami pod sufitem', 'sala', 1.0, 1, 28),
(129, 1, 'photo', 'assets/photos/ola/stol-okragly-dalie.jpg', 'stol-okragly-dalie.jpg', 'image/jpeg', 0, 'okrągły stół z daliami', 'sala', 1.0, 1, 29),
(130, 1, 'photo', 'assets/photos/ola/bukiet-oxypetalum.jpg', 'bukiet-oxypetalum.jpg', 'image/jpeg', 0, 'bukiet z różami i oxypetalum', 'bukiety', 1.0, 1, 30),
(131, 1, 'photo', 'assets/photos/ola/ramy-domki-las.jpg', 'ramy-domki-las.jpg', 'image/jpeg', 0, 'białe ramy w lesie i kompozycja przy ławce', 'ceremonia', 1.0, 1, 31),
(132, 1, 'photo', 'assets/photos/ola/kalie-jaskry-limonki.jpg', 'kalie-jaskry-limonki.jpg', 'image/jpeg', 0, 'kalie, jaskry i limonki', 'sala', 1.0, 1, 32),
(133, 1, 'photo', 'assets/photos/ola/para-kalie-welon.jpg', 'para-kalie-welon.jpg', 'image/jpeg', 0, 'para młoda, kalie i welon', 'para', 1.0, 1, 33),
(134, 1, 'photo', 'assets/photos/ola/pergola-gipsowka.jpg', 'pergola-gipsowka.jpg', 'image/jpeg', 0, 'pergola z gipsówką i różami', 'ceremonia', 1.0, 1, 34),
(135, 1, 'photo', 'assets/photos/ola/panna-mloda-wstazki-bukiet.jpg', 'panna-mloda-wstazki-bukiet.jpg', 'image/jpeg', 0, 'panna młoda z kolorowym bukietem', 'bukiety', 1.0, 1, 35),
(136, 1, 'photo', 'assets/photos/ola/stodola-stoly.jpg', 'stodola-stoly.jpg', 'image/jpeg', 0, 'stoły w stodole z polnymi kwiatami', 'sala', 1.0, 1, 36),
(137, 1, 'photo', 'assets/photos/ola/postument-anturium.jpg', 'postument-anturium.jpg', 'image/jpeg', 0, 'anturium i amarantus na postumencie', 'detale', 1.2, 1, 37),
(138, 1, 'photo', 'assets/photos/ola/aleja-ogrod-kolumny.jpg', 'aleja-ogrod-kolumny.jpg', 'image/jpeg', 0, 'aleja w ogrodzie z kwiatowymi kolumnami', 'ceremonia', 1.0, 1, 38),
(139, 1, 'photo', 'assets/photos/ola/stol-zielony-kalie.jpg', 'stol-zielony-kalie.jpg', 'image/jpeg', 0, 'zielona kompozycja na stole', 'sala', 1.0, 1, 39),
(140, 1, 'photo', 'assets/photos/ola/panna-mloda-drewno.jpg', 'panna-mloda-drewno.jpg', 'image/jpeg', 0, 'panna młoda z bukietem róż', 'bukiety', 1.0, 1, 40),
(141, 1, 'photo', 'assets/photos/ola/chabry-kosmos-zblizenie.jpg', 'chabry-kosmos-zblizenie.jpg', 'image/jpeg', 0, 'chabry, ostróżki i kosmos', 'detale', 1.0, 1, 41),
(142, 1, 'photo', 'assets/photos/ola/przysiega-wstazki.jpg', 'przysiega-wstazki.jpg', 'image/jpeg', 0, 'przysięga przy kolorowych wstążkach', 'ceremonia', 1.0, 1, 42),
(143, 1, 'photo', 'assets/photos/ola/bieznik-z-gory.jpg', 'bieznik-z-gory.jpg', 'image/jpeg', 0, 'bieżnik z bursztynowym szkłem', 'sala', 1.0, 1, 43),
(144, 1, 'photo', 'assets/photos/ola/anturium-zblizenie.jpg', 'anturium-zblizenie.jpg', 'image/jpeg', 0, 'anturium z bliska', 'detale', 1.0, 1, 44),
(145, 1, 'photo', 'assets/photos/ola/para-kosciol.jpg', 'para-kosciol.jpg', 'image/jpeg', 0, 'para młoda przed kościołem', 'para', 1.0, 1, 45),
(146, 1, 'photo', 'assets/photos/ola/luk-chabry.jpg', 'luk-chabry.jpg', 'image/jpeg', 0, 'pergola z chabrami i ostróżką', 'ceremonia', 1.0, 1, 46),
(147, 1, 'photo', 'assets/photos/ola/stol-dalie-czerwone.jpg', 'stol-dalie-czerwone.jpg', 'image/jpeg', 0, 'czerwone dalie w fioletowym wazonie', 'sala', 1.0, 1, 47),
(148, 1, 'photo', 'assets/photos/ola/bukiet-anturium.jpg', 'bukiet-anturium.jpg', 'image/jpeg', 0, 'bukiet z anturium', 'bukiety', 1.0, 1, 48),
(149, 1, 'photo', 'assets/photos/ola/gipsowka-roze-zblizenie.jpg', 'gipsowka-roze-zblizenie.jpg', 'image/jpeg', 0, 'gipsówka i białe róże', 'detale', 1.0, 1, 49),
(150, 1, 'photo', 'assets/photos/ola/panna-mloda-luk.jpg', 'panna-mloda-luk.jpg', 'image/jpeg', 0, 'panna młoda przy kwiatowym łuku', 'ceremonia', 1.0, 1, 50),
(151, 1, 'photo', 'assets/photos/ola/winogrona-limonki.jpg', 'winogrona-limonki.jpg', 'image/jpeg', 0, 'winogrona i limonki na stole', 'detale', 1.0, 1, 51),
(152, 1, 'photo', 'assets/photos/ola/panna-mloda-zachod.jpg', 'panna-mloda-zachod.jpg', 'image/jpeg', 0, 'bukiet o zachodzie słońca', 'bukiety', 1.0, 1, 52),
(153, 1, 'photo', 'assets/photos/ola/stol-usc-plener.jpg', 'stol-usc-plener.jpg', 'image/jpeg', 0, 'stół do ślubu cywilnego w plenerze', 'ceremonia', 1.0, 1, 53),
(154, 1, 'photo', 'assets/photos/ola/sala-czarno-biala.jpg', 'sala-czarno-biala.jpg', 'image/jpeg', 0, 'sala weselna, czarno-białe', 'sala', 1.0, 1, 54),
(155, 1, 'photo', 'assets/photos/ola/kapliczka-las.jpg', 'kapliczka-las.jpg', 'image/jpeg', 0, 'drewniana kapliczka w lesie', 'ceremonia', 1.0, 1, 55),
(156, 1, 'photo', 'assets/photos/ola/para-obraczki-wstazki.jpg', 'para-obraczki-wstazki.jpg', 'image/jpeg', 0, 'para młoda z obrączkami', 'para', 1.0, 1, 56),
(157, 1, 'photo', 'assets/photos/ola/dalie-brzoskwiniowe.jpg', 'dalie-brzoskwiniowe.jpg', 'image/jpeg', 0, 'brzoskwiniowe dalie i świece', 'sala', 1.2, 1, 57),
(158, 1, 'photo', 'assets/photos/ola/bukiet-brzoskwiniowy.jpg', 'bukiet-brzoskwiniowy.jpg', 'image/jpeg', 0, 'bukiet brzoskwiniowy z bliska', 'bukiety', 1.0, 1, 58),
(159, 1, 'photo', 'assets/photos/ola/pampasy-kolumny.jpg', 'pampasy-kolumny.jpg', 'image/jpeg', 0, 'kolumny z pampasów', 'ceremonia', 1.0, 1, 59),
(160, 1, 'photo', 'assets/photos/ola/stol-bodziszek.jpg', 'stol-bodziszek.jpg', 'image/jpeg', 0, 'bodziszek i krwiściąg na stole', 'detale', 1.0, 1, 60),
(161, 1, 'photo', 'assets/photos/ola/ceremonia-ogrod.jpg', 'ceremonia-ogrod.jpg', 'image/jpeg', 0, 'ceremonia w ogrodzie', 'ceremonia', 1.0, 1, 61),
(162, 1, 'photo', 'assets/photos/ola/stol-bursztyn.jpg', 'stol-bursztyn.jpg', 'image/jpeg', 0, 'stół z bursztynowym szkłem', 'sala', 1.0, 1, 62),
(163, 1, 'photo', 'assets/photos/ola/anturium-rozowy-wazon.jpg', 'anturium-rozowy-wazon.jpg', 'image/jpeg', 0, 'anturium w różowym wazonie', 'detale', 1.2, 1, 63),
(164, 1, 'photo', 'assets/photos/ola/para-plot.jpg', 'para-plot.jpg', 'image/jpeg', 0, 'para młoda przy płocie', 'para', 1.0, 1, 64),
(165, 1, 'photo', 'assets/photos/ola/polne-przy-lawce.jpg', 'polne-przy-lawce.jpg', 'image/jpeg', 0, 'kwiaty polne przy ławce', 'ceremonia', 1.0, 1, 65),
(166, 1, 'photo', 'assets/photos/ola/rumianek-butelka.jpg', 'rumianek-butelka.jpg', 'image/jpeg', 0, 'rumianek w czerwonej butelce', 'detale', 1.0, 1, 66),
(167, 1, 'photo', 'assets/photos/ola/trytoma-slonce.jpg', 'trytoma-slonce.jpg', 'image/jpeg', 0, 'trytoma i anturium w słońcu', 'sala', 1.2, 1, 67),
(168, 1, 'photo', 'assets/photos/ola/bukiet-stolik-z-gory.jpg', 'bukiet-stolik-z-gory.jpg', 'image/jpeg', 0, 'bukiet na stoliku', 'detale', 1.0, 1, 68),
(169, 1, 'photo', 'assets/photos/ola/stol-kalie-krzesla.jpg', 'stol-kalie-krzesla.jpg', 'image/jpeg', 0, 'kalie na stole i krzesła krzyżakowe', 'sala', 1.0, 1, 69),
(170, 1, 'photo', 'assets/photos/ola/para-bukiet-w-gore.jpg', 'para-bukiet-w-gore.jpg', 'image/jpeg', 0, 'panna młoda unosi bukiet', 'para', 1.0, 1, 70),
(171, 1, 'photo', 'assets/photos/ola/dalie-fiolet.jpg', 'dalie-fiolet.jpg', 'image/jpeg', 0, 'dalie w fioletowym szkle', 'detale', 1.2, 1, 71),
(172, 1, 'photo', 'assets/photos/ola/astilbe-niebieski-wazon.jpg', 'astilbe-niebieski-wazon.jpg', 'image/jpeg', 0, 'astilbe w niebieskim wazonie', 'detale', 1.2, 1, 72),
(173, 1, 'photo', 'assets/photos/ola/gozdzik-zielony.jpg', 'gozdzik-zielony.jpg', 'image/jpeg', 0, 'zielone goździki i amarantus', 'detale', 1.2, 1, 73),
(174, 1, 'photo', 'assets/photos/ola/stol-menu-wieczor.jpg', 'stol-menu-wieczor.jpg', 'image/jpeg', 0, 'stół wieczorem z menu', 'sala', 1.0, 1, 74),
(175, 1, 'photo', 'assets/photos/ola/ogrod-postumenty.jpg', 'ogrod-postumenty.jpg', 'image/jpeg', 0, 'kompozycje na postumentach w ogrodzie', 'ceremonia', 1.0, 1, 75);

INSERT IGNORE INTO of_brands
  (id, tenant_id, slug, name, display_name, color_hex, dot_token, logo_media_id,
   sender_name, sender_email, mail_footer, mail_signature, number_prefix, public_domain)
VALUES
(1, 1, 'of', 'ola flora', 'Ola flora — dekoracje ślubne', '#cdcb9a', NULL, NULL,
 'Ola flora', '', 'Ola flora · dekoracje ślubne · {{mail}} · {{telefon}}', 'Ola\nOla flora', 'OF', 'olaflora.pl');

-- ---------------------------------------------------------------------
-- Décision 1 : une seule chaîne de 7 étapes (§2.3) + étape hors tableau
-- ---------------------------------------------------------------------

INSERT IGNORE INTO of_stages
  (id, tenant_id, slug, label, short_label, view_all_label, funnel_label, sort, is_pipeline, is_terminal, occupies_slot)
VALUES
(1, 1, 'zapytanie',            'zapytanie',            'zapytanie',          'zapytanie',     'zapytanie',      1, 1, 0, 0),
(2, 1, 'rozmowa',              'rozmowa / wycena',     'wycena',             'wycena',        'wycena wysłana', 2, 1, 0, 0),
(3, 1, 'oferta_wyslana',       'oferta wysłana',       'wysłana',            'wycena',        'wycena wysłana', 3, 1, 0, 0),
(4, 1, 'oferta_zaakceptowana', 'oferta zaakceptowana', 'zaakceptowana',      'zaakceptowane', 'zaakceptowane',  4, 1, 0, 0),
(5, 1, 'zadatek_oplacony',     'zadatek opłacony',     'zadatek (opłacone)', 'opłacone',      'opłacone',       5, 1, 0, 1),
(6, 1, 'doplata_oplacona',     'dopłata opłacona',     'przygotowania',      'opłacone',      'opłacone',       6, 1, 0, 1),
(7, 1, 'zrealizowane',         'zrealizowane',         'zrealizowane',       'zamknięte',     NULL,             7, 1, 1, 1),
(8, 1, 'odrzucone',            'odmowa / rezygnacja',  'odrzucone',          'zamknięte',     NULL,            99, 0, 1, 0);

-- ---------------------------------------------------------------------
-- 4.11 Ustawienia pracowni (sans données personnelles)
-- ---------------------------------------------------------------------

INSERT IGNORE INTO of_settings (id, tenant_id, brand_id, skey, svalue, value_type, label) VALUES
(1,  1, NULL, 'company_name',              'Ola flora — dekoracje ślubne', 'text', 'Nazwa pracowni'),
(2,  1, NULL, 'brand_color_hex',           '#cdcb9a', 'text', 'Kolor marki'),
(3,  1, NULL, 'brand_color_options',       '["#cdcb9a","#465802","#f1acc0","#730216","#f2edbd","#6c2c25"]', 'json', 'Kolor marki — opcje'),
(4,  1, NULL, 'nip',                       '', 'text', 'NIP'),
(5,  1, NULL, 'address',                   '', 'text', 'Adres pracowni'),
(6,  1, NULL, 'phone',                     '', 'text', 'Telefon'),
(7,  1, NULL, 'email',                     '', 'text', 'E-mail'),
(8,  1, NULL, 'opening_hours',             'pon.–sob. 9:00–18:00', 'text', 'Godziny otwarcia'),
(9,  1, NULL, 'shop_domain',               'olaflora.pl', 'text', 'Domena sklepu'),
(10, 1, NULL, 'instagram',                 '@olaflora', 'text', 'Instagram'),
(11, 1, NULL, 'atelier_address',           '', 'text', 'Adres startowy do liczenia km'),
(12, 1, NULL, 'atelier_lat',               '', 'text', 'Atelier — szerokość geogr.'),
(13, 1, NULL, 'atelier_lng',               '', 'text', 'Atelier — długość geogr.'),
(14, 1, NULL, 'tpay_merchant_id',          '', 'text', 'Tpay — ID sprzedawcy'),
(15, 1, NULL, 'bank_account',              '', 'text', 'Numer konta'),
(16, 1, NULL, 'km_rate_gr',                '320', 'money_gr', 'Stawka za kilometr'),
(17, 1, NULL, 'km_own_cost_gr',            '185', 'money_gr', 'Koszt własny kilometra'),
(18, 1, NULL, 'florist_hour_rate_gr',      '9500', 'money_gr', 'Stawka godzinowa florystki'),
(19, 1, NULL, 'deposit_pct',               '30', 'pct', 'Zadatek (= rata I)'),
(20, 1, NULL, 'vat_pct',                   '8', 'pct', 'Stawka VAT'),
(21, 1, NULL, 'weekend_fee_pct',           '20', 'pct', 'Dodatek weekendowy'),
(22, 1, NULL, 'night_fee_gr',              '18000', 'money_gr', 'Dodatek nocny (po 21:00)'),
(23, 1, NULL, 'night_from_hour',           '21', 'int', 'Dodatek nocny od godziny'),
(24, 1, NULL, 'offer_validity_days',       '30', 'int', 'Ważność oferty (dni)'),
(25, 1, NULL, 'floral_cost_ratio',         '0.42', 'text', 'Współczynnik kosztu kwiatów (gdy brak kosztu pozycji)'),
(26, 1, NULL, 'day_capacity',              '4', 'int', 'Realizacje na dzień'),
(27, 1, NULL, 'form_target_email',         '', 'text', 'Mail docelowy formularza'),
(28, 1, NULL, 'form_sla_text',             'dwa dni robocze', 'text', 'Czas odpowiedzi (formularz)'),
(29, 1, NULL, 'call_base_price_gr',        '267500', 'money_gr', 'Cena pakietu bazowego (asystent) = suma pozycji'),
(30, 1, NULL, 'final_payment_days_before', '14', 'int', 'Płatność końcowa — dni przed wydarzeniem'),
(31, 1, NULL, 'live_offer_deposit_days',   '7', 'int', 'Trzymanie terminu po rozmowie (dni)'),
(32, 1, NULL, 'inquiry_number_prefix',     'ZAP', 'text', 'Prefiks numeru zapytania'),
(33, 1, NULL, 'creator_defaults_json',
 '{"hours":{"from":"08:00","to":"22:00"},"transport":[{"code":"bus","qty":1,"mode":"blok","hours":8},{"code":"laweta","qty":1,"mode":"blok","hours":4},{"code":"kurier","qty":0,"mode":"godz","hours":3}],"personel":[{"code":"florystka","qty":3,"mode":"godz","hours":8},{"code":"asystent","qty":2,"mode":"godz","hours":6},{"code":"kierownik","qty":1,"mode":"blok","hours":8},{"code":"demontaz","qty":2,"mode":"godz","hours":3}],"sprzet":[{"code":"waza40","qty":12,"mode":"blok"},{"code":"stelaz","qty":1,"mode":"blok"},{"code":"lampion","qty":24,"mode":"blok"}],"default_package":"piwonia"}',
 'json', 'Kreator oferty — ustawienia domyślne'),
(34, 1, NULL, 'client_addons_json',
 '[{"code":"swiece","label":"Świece na stołach, 60 szt.","price_gr":48000,"default_on":true},{"code":"brama2","label":"Druga ścianka do zdjęć","price_gr":160000,"default_on":false},{"code":"demontaz","label":"Demontaż tej samej nocy","price_gr":60000,"default_on":false}]',
 'json', 'Dodatki w widoku klienta'),
(35, 1, NULL, 'call_budget_chips_json',    '[400000,600000,800000,1000000,1500000]', 'json', 'Asystent — chipy budżetu (gr)'),
(36, 1, NULL, 'call_guest_options_json',   '[30,50,70,90,110,130,150,200]', 'json', 'Asystent — liczba gości (stoły = ceil(n/10))'),
(37, 1, NULL, 'offer_pdf_terms_json',
 '[{"title":"Ważność oferty","text":"30 dni od wystawienia. Po tym terminie ceny kwiatów weryfikuję ponownie."},{"title":"Zadatek","text":"30% przy podpisaniu, płatne linkiem Tpay. Blokuje termin w kalendarzu."},{"title":"Rata druga","text":"35%, 90 dni przed weselem."},{"title":"Płatność końcowa","text":"35%, 14 dni przed weselem."},{"title":"Zmiany w zakresie","text":"Do 30 dni przed terminem bez opłat. Później tylko dodatki, bez zmniejszania zakresu."},{"title":"Rezygnacja","text":"Zadatek pokrywa zamówione kwiaty i rezerwację terminu, nie podlega zwrotowi."},{"title":"Wizja lokalna","text":"Jedno spotkanie w miejscu przyjęcia w cenie, ustalane po podpisaniu."}]',
 'json', 'Oferta PDF — warunki (str. 3)'),
(38, 1, NULL, 'live_offer_terms',
 'Termin rezerwujemy po wpłacie zadatku. Zmiany zakresu bez kosztów do 30 dni przed ślubem. Kwiaty dobieramy do sezonu — jeśli któryś gatunek będzie niedostępny, proponujemy zamiennik w tej samej cenie. Pełna umowa przyjdzie mailem razem z linkiem do płatności.',
 'text', 'Oferta na żywo — warunki'),
(39, 1, NULL, 'contract_payment_text',
 'Płatność linkiem Tpay (przelew, BLIK, karta) albo przelewem na konto {{konto}}. W tytule numer oferty {{numer_oferty}}. Za termin płatności uznaje się dzień zaksięgowania środków. Opóźnienie powyżej 7 dni uprawnia wykonawcę do wstrzymania realizacji do czasu wpłaty.',
 'text', 'Umowa — akapit płatności'),
(40, 1, NULL, 'contract_final_text',
 'Umowę sporządzono w dwóch jednobrzmiących egzemplarzach, po jednym dla każdej ze stron. Podpisanie skanu i odesłanie mailem jest równoważne. Załącznikiem do umowy jest oferta {{numer_oferty}} wraz z wyceną pozycja po pozycji i harmonogramem dnia.',
 'text', 'Umowa — akapit końcowy'),
(41, 1, NULL, 'disclaimer_seasonal',
 'Zdjęcia pokazują styl kompozycji, nie są zamówionym produktem. Kwiaty to towar świeży i sezonowy.',
 'text', 'Zastrzeżenie — sezonowość'),
(42, 1, NULL, 'disclaimer_wholesale',
 'Ceny policzone są według stawek i cen kwiatów z tego dnia. Przy tak długim wyprzedzeniu ceny hurtowe mogą się zmienić — jeśli w chwili dopłaty koszt zakupu wzrośnie, przedstawimy wyliczenie różnicy i poprosimy o jej wyrównanie. Zmiana zawsze jest pokazana pozycja po pozycji, przed płatnością.',
 'text', 'Zastrzeżenie — ceny hurtowe'),
(43, 1, NULL, 'pdf_day_schedule_json',
 '[{"at":"06:30","what":"Wyjazd z pracowni, kwiaty w chłodni transportowej"},{"at":"08:00","what":"Montaż dekoracji w kościele"},{"at":"11:00","what":"Dostawa bukietu i butonierek do Panny Młodej"},{"at":"12:30","what":"Montaż sali: stoły, brama, strefa tortu"},{"at":"15:00","what":"Ceremonia — zespół zostaje do przeniesienia kompozycji"},{"at":"22:00","what":"Koniec obsługi; demontaż po ostatnim tańcu"}]',
 'json', 'Oferta PDF — przykładowy harmonogram dnia'),
(44, 1, NULL, 'day_template_json',
 '{"typowy":[{"at":"07:30","min":60,"what":"Załadunek w pracowni","where":"pracownia","team":"2 florystki + kierowca","kind":"zaladunek"},{"at":"09:00","min":150,"what":"Montaż ceremonii","where":"kościół","team":"3 florystki","kind":"montaz"},{"at":"12:30","min":30,"what":"Przejazd na salę","where":null,"team":"cały zespół","kind":"przejazd"},{"at":"13:00","min":210,"what":"Dekoracja sali i stołów","where":"sala","team":"3 florystki + pomoc","kind":"montaz"},{"at":"16:30","min":60,"what":"Ceremonia — obecność na miejscu","where":null,"team":"1 florystka","kind":"obecnosc"},{"at":"23:30","min":90,"what":"Demontaż i odbiór sprzętu","where":"sala","team":"2 florystki","kind":"demontaz"}],"plener":["Brama i ławki w plenerze","Stoły prostokątne, girlandy","Ceremonia w plenerze"],"duzy":["Załadunek — duża realizacja","Instalacja wisząca w stodole","Stoły mieszane i strefa tortu","Demontaż nocny"]}',
 'json', 'Zapytania — „jak wygląda ten dzień” (szablon)'),
(45, 1, NULL, 'form_kinds_json',
 '[{"kind":"text","label":"tekst","example":"jedno pole, np. „Pałac Ożarów Mazowiecki”"},{"kind":"two_fields","label":"dwa pola","example":"dwa pola obok siebie, np. imię i imię"},{"kind":"date","label":"kalendarz","example":"wybór dnia z kalendarza, np. 13 czerwca 2026"},{"kind":"choice","label":"wybór (jedna)","example":"lista kafelków, jedna odpowiedź, np. „okrągłe”"},{"kind":"multi","label":"wielokrotny","example":"kafelki, wiele odpowiedzi, np. słodki stół + księga gości"},{"kind":"choice_photo","label":"wybór + zdjęcie","example":"kafelki ze zdjęciem stylu, np. „boho”"},{"kind":"colors","label":"palety kolorów","example":"paleta + akcenty + pole na uwagi"},{"kind":"number","label":"liczba","example":"licznik ze strzałkami, np. 8 stołów"},{"kind":"contact","label":"kontakt","example":"mail i telefon, z walidacją"},{"kind":"long_text","label":"długi tekst","example":"pole wielolinijkowe na opis wizji"}]',
 'json', 'Formularz — typy odpowiedzi'),
(46, 1, NULL, 'form_calendar_start',       '2027-06', 'text', 'Formularz — miesiąc startowy kalendarza'),
(47, 1, NULL, 'gallery_categories_json',
 '[{"code":null,"label":"wszystko"},{"code":"bukiety","label":"bukiety"},{"code":"ceremonia","label":"ceremonia"},{"code":"sala","label":"sala"},{"code":"detale","label":"detale"},{"code":"firmy","label":"dla firm"},{"code":"kwiaty","label":"kwiaty"},{"code":"para","label":"para młoda"}]',
 'json', 'Strona — kategorie galerii'),
(48, 1, NULL, 'realisation_moods_json',    '["bez zgrzytów","dobrze, z poprawkami","było ciężko","nie powtarzam"]', 'json', 'Realizacje — ocena „Jak poszło?”'),
(49, 1, NULL, 'realisation_photo_slots_json', '[{"code":"okladka","label":"okładka"},{"code":"ceremonia","label":"ceremonia"},{"code":"sala","label":"sala"},{"code":"detale","label":"detale"}]', 'json', 'Realizacje — sloty zdjęć');

-- Intégrations : configuration non secrète seulement, statut « nieskonfig »
INSERT IGNORE INTO of_integrations (id, tenant_id, kind, config_json, secret_enc, status) VALUES
(1, 1, 'smtp',        '{"host":"smtp.olaflora.pl","port":587,"secure":"starttls","login":"","from_name":"Ola flora","from_email":""}', NULL, 'nieskonfig'),
(2, 1, 'sms',         '{"provider":"smsapi.pl","sender":"OlaFlora"}', NULL, 'nieskonfig'),
(3, 1, 'google_maps', '{}', NULL, 'nieskonfig'),
(4, 1, 'tpay',        '{"merchant_id":""}', NULL, 'nieskonfig'),
(5, 1, 'imap',        '{"host":"","port":993,"secure":"ssl","login":""}', NULL, 'nieskonfig');

-- ---------------------------------------------------------------------
-- 4.3 Lieux (objets publics, km depuis l'atelier, aller simple)
-- ---------------------------------------------------------------------

INSERT IGNORE INTO of_venues (id, tenant_id, name, address, kind, distance_km, remembered) VALUES
(1,  1, 'Kościół św. Anny, Wilanów',                  NULL, 'kosciol', 14.0, 1),
(2,  1, 'Pałac Ożarów Mazowiecki',                    NULL, 'sala',    31.0, 1),
(3,  1, 'Dwór Konstancin',                            NULL, 'sala',    23.0, 1),
(4,  1, 'Stodoła Radzików',                           NULL, 'sala',    46.0, 1),
(5,  1, 'Kościół Wniebowzięcia NMP, Grójec',          NULL, 'kosciol', 38.0, 1),
(6,  1, 'Pałac w Nieborowie',                         NULL, 'sala',    72.0, 1),
(7,  1, 'Kościół św. Aleksandra, pl. Trzech Krzyży',  NULL, 'kosciol',  6.0, 1),
(8,  1, 'Bazylika Archikatedralna, Warszawa',         NULL, 'kosciol',  7.0, 1),
(9,  1, 'Urząd Stanu Cywilnego, Warszawa',            NULL, 'usc',      5.0, 1),
(10, 1, 'Ceremonia w plenerze, na miejscu przyjęcia', NULL, 'plener',  NULL, 1),
(11, 1, 'Folwark Pańska Wola',                        NULL, 'sala',    38.0, 1),
(12, 1, 'Hotel Bristol, Warszawa',                    NULL, 'hotel',    6.0, 1);

-- ---------------------------------------------------------------------
-- 4.7 Formularz (wizard) — 12 kroków
-- ---------------------------------------------------------------------

INSERT IGNORE INTO of_form_questions
  (id, tenant_id, brand_id, qkey, sort, eyebrow, question, hint, caption, kind, active, required, photo_media_id, placeholder, max_choices)
VALUES
(1,  1, 1, 'imiona',      1,  'zaczynamy',   'Jak się nazywacie?',
     'Tak będę się do Was zwracać w całej rozmowie.', 'Anna i Marek, czerwiec 2026', 'two_fields', 1, 1, 2, NULL, NULL),
(2,  1, 1, 'data',        2,  'termin',      'Kiedy jest Wasze wesele?',
     'Wybierzcie dzień w kalendarzu. Jeśli termin nie jest jeszcze pewny, zaznaczcie przybliżony.', 'Sobotnie terminy w czerwcu schodzą pierwsze.', 'date', 1, 1, 15, NULL, NULL),
(3,  1, 1, 'miejsce',     3,  'miejsce',     'Gdzie odbędzie się przyjęcie?',
     'Nazwa sali albo adres. Od tego liczę dojazd i montaż.', 'Zawsze robię wizję lokalną przed montażem.', 'text', 1, 0, 1, NULL, NULL),
(4,  1, 1, 'goscie',      4,  'skala',       'Ilu gości planujecie?',
     'Liczba gości przekłada się wprost na liczbę kompozycji.', 'Osiemdziesiąt osób to zwykle dziesięć stołów.', 'choice', 1, 0, 10, NULL, NULL),
(5,  1, 1, 'stoly',       5,  'układ sali',  'Jakie stoły będą na sali?',
     'Okrągłe biorą niskie kompozycje, prostokątne — biegnące girlandy.', 'Prostokątne stoły pozwalają na girlandy przez cały blat.', 'choice', 1, 0, 14, NULL, NULL),
(6,  1, 1, 'prezydialny', 6,  'układ sali',  'Siedzicie z gośćmi czy przy osobnym stole?',
     'Stół prezydialny dekoruję inaczej — z frontem widocznym dla sali.', 'Front stołu prezydialnego widzi cała sala.', 'choice', 1, 0, 17, NULL, NULL),
(7,  1, 1, 'ceremonia',   7,  'ceremonia',   'Gdzie będzie ceremonia?',
     'Kościół, plener czy urząd — każde miejsce ma inne zasady montażu.', 'W plenerze liczy się wiatr i godzina słońca.', 'choice', 1, 0, 18, NULL, NULL),
(8,  1, 1, 'dodatki',     8,  'zakres',      'Co jeszcze dekorujemy?',
     'Zaznaczcie wszystko, co planujecie — także to niepewne.', 'Strefy dodatkowe wyceniam osobną pozycją.', 'multi', 1, 0, 6, NULL, NULL),
(9,  1, 1, 'styl',        9,  'styl',        'Jaki styl jest Wam najbliższy?',
     'Wybierzcie jeden — pokażę, jakie kwiaty za nim stoją.', NULL, 'choice_photo', 1, 0, 3, NULL, NULL),
(10, 1, 1, 'kolory',      10, 'kolorystyka', 'Jaka kolorystyka Wam się podoba?',
     'Wybierzcie paletę, potem dorzućcie akcent. Napiszcie też, czego na pewno nie chcecie.', 'Paleta zmienia się z sezonem — dobieram najbliższą odmianę.', 'colors', 1, 0, 4, 'Biel, kremy, dużo zieleni. Bez różu.', 2),
(11, 1, 1, 'budzet',      11, 'budżet',      'Jaki budżet planujecie?',
     'Widełki wystarczą. Dzięki nim od razu proponuję realny zakres.', 'Podaję ceny pozycja po pozycji, bez ryczałtu.', 'choice', 1, 0, 9, NULL, NULL),
(12, 1, 1, 'kontakt',     12, 'na koniec',   'Gdzie mam wysłać propozycję?',
     'Mail i telefon. Odpowiadam w dwa dni robocze.', 'Zapytanie ląduje prosto na moim mailu.', 'contact', 1, 1, 7, NULL, NULL);

INSERT IGNORE INTO of_form_question_fields (id, question_id, fkey, placeholder, input_type, sort) VALUES
(1, 1,  'ona',  'Panna Młoda',             'text',  1),
(2, 1,  'on',   'Pan Młody',               'text',  2),
(3, 3,  'sala', 'Pałac Ożarów Mazowiecki', 'text',  1),
(4, 12, 'mail', 'anna@example.com',        'email', 1),
(5, 12, 'tel',  '+48 600 000 000',         'tel',   2);

INSERT IGNORE INTO of_form_question_options (id, question_id, label, sort, photo_media_id, description) VALUES
-- goście
(401, 4, 'do 50 osób',        1, NULL, NULL),
(402, 4, '51–80 osób',        2, NULL, NULL),
(403, 4, '81–120 osób',       3, NULL, NULL),
(404, 4, '121–160 osób',      4, NULL, NULL),
(405, 4, 'powyżej 160 osób',  5, NULL, NULL),
-- stoły
(501, 5, 'okrągłe',           1, NULL, NULL),
(502, 5, 'prostokątne',       2, NULL, NULL),
(503, 5, 'mieszane',          3, NULL, NULL),
(504, 5, 'jeszcze nie wiemy', 4, NULL, NULL),
-- prezydialny
(601, 6, 'z gośćmi',                1, NULL, NULL),
(602, 6, 'przy stole prezydialnym', 2, NULL, NULL),
(603, 6, 'jeszcze nie wiemy',       3, NULL, NULL),
-- ceremonia
(701, 7, 'w kościele',        1, NULL, NULL),
(702, 7, 'w plenerze',        2, NULL, NULL),
(703, 7, 'w urzędzie',        3, NULL, NULL),
(704, 7, 'kościół i plener',  4, NULL, NULL),
-- dodatki
(801, 8, 'słodki stół',       1, NULL, NULL),
(802, 8, 'księga dla gości',  2, NULL, NULL),
(803, 8, 'strefa ogrodowa',   3, NULL, NULL),
(804, 8, 'lustro selfie',     4, NULL, NULL),
(805, 8, 'ścianka do zdjęć',  5, NULL, NULL),
(806, 8, 'strefa tortu',      6, NULL, NULL),
(807, 8, 'plan sali',         7, NULL, NULL),
-- styl (4.7a)
(901, 9, 'klasyczny i elegancki', 1, 12, 'Kalie, róże eustoma, dużo bieli i zieleni.'),
(902, 9, 'romantyczny',           2, 8,  'Piwonie, róże ogrodowe, eustoma w pastelach.'),
(903, 9, 'nowoczesny',            3, 13, 'Antorium, storczyki, mocna forma i mało gatunków.'),
(904, 9, 'minimalistyczny',       4, 11, 'Jeden gatunek, powtórzony. Kalie albo tulipany.'),
(905, 9, 'garden / naturalny',    5, 1,  'Kosmos, dzika marchew, trawy i zioła.'),
(906, 9, 'glamour',               6, 9,  'Róże, hortensje, amarantus — pełne, ciężkie kompozycje.'),
(907, 9, 'boho',                  7, 20, 'Cynie, dalie, suchy pampas i wstążki.'),
(908, 9, 'jeszcze nie wiemy',     8, 4,  'Bez obaw — dobierzemy razem przy pierwszej rozmowie.'),
-- budżet
(1101, 11, '8 000 – 15 000 zł',  1, NULL, NULL),
(1102, 11, '15 000 – 25 000 zł', 2, NULL, NULL),
(1103, 11, 'powyżej 25 000 zł',  3, NULL, NULL),
(1104, 11, 'jeszcze nie wiemy',  4, NULL, NULL);

-- 4.7b Palettes, accents, palette source
INSERT IGNORE INTO of_palettes (id, tenant_id, label, swatches_json, is_custom, visible, sort) VALUES
(1, 1, 'biel i zieleń',    '["#ffffff","#eef1e6","#8f9d74","#465802"]', 0, 1, 1),
(2, 1, 'pastele',          '["#f7e2e6","#f1acc0","#e8d3e6","#cfd9ee"]', 0, 1, 2),
(3, 1, 'ciepłe terakoty',  '["#f3ddc9","#e0a07a","#c2603f","#7a3324"]', 0, 1, 3),
(4, 1, 'burgund i róż',    '["#f1acc0","#c4577a","#730216","#3d0a12"]', 0, 1, 4),
(5, 1, 'polne kolory',     '["#f7d774","#e8834a","#c86b9a","#7ca2e0"]', 0, 1, 5),
(6, 1, 'kremy i złoto',    '["#fdf7ec","#f0e0c0","#d4b483","#9a7b4f"]', 0, 1, 6);

INSERT IGNORE INTO of_accent_colors (id, tenant_id, hex, active, sort) VALUES
(1, 1, '#730216', 1, 1), (2, 1, '#f1acc0', 1, 2), (3, 1, '#465802', 1, 3), (4, 1, '#eb5e3a', 1, 4),
(5, 1, '#7ca2e0', 1, 5), (6, 1, '#f2edbd', 1, 6), (7, 1, '#6c2c25', 1, 7), (8, 1, '#ffffff', 1, 8);

INSERT IGNORE INTO of_color_library (id, tenant_id, name, hex, sort) VALUES
(1,  1, 'biel',           '#ffffff', 1),
(2,  1, 'kość słoniowa',  '#f2edbd', 2),
(3,  1, 'krem',           '#fdf7ec', 3),
(4,  1, 'szałwia jasna',  '#eef1e6', 4),
(5,  1, 'szałwia',        '#cdcb9a', 5),
(6,  1, 'oliwka',         '#8f9d74', 6),
(7,  1, 'oliwka ciemna',  '#465802', 7),
(8,  1, 'butelkowa',      '#2b3208', 8),
(9,  1, 'róż jasny',      '#f7e2e6', 9),
(10, 1, 'róż',            '#f1acc0', 10),
(11, 1, 'malina',         '#c4577a', 11),
(12, 1, 'burgund',        '#730216', 12),
(13, 1, 'terakota',       '#c2603f', 13),
(14, 1, 'pomidor',        '#eb5e3a', 14),
(15, 1, 'cegła',          '#6c2c25', 15),
(16, 1, 'złoto',          '#d4b483', 16),
(17, 1, 'chaber',         '#7ca2e0', 17),
(18, 1, 'lawenda',        '#e8d3e6', 18);

-- ---------------------------------------------------------------------
-- 4.1 Katalog weselny (prix / coûts en grosze)
-- ---------------------------------------------------------------------

INSERT IGNORE INTO of_catalogue_categories (id, tenant_id, brand_id, name, sort) VALUES
(1, 1, 1, 'para młoda', 1),
(2, 1, 1, 'ceremonia',  2),
(3, 1, 1, 'przyjęcie',  3),
(4, 1, 1, 'usługi',     4);

INSERT IGNORE INTO of_catalogue_items
  (id, tenant_id, brand_id, code, sku, category_id, name, price_gr, cost_gr, unit, default_qty,
   in_base_package, photo_media_id, pitch, offer_section, active, sort)
VALUES
(1,  1, 1, 'bukiet',        'OF-PM-01',  1, 'Bukiet panny młodej, sezonowy',            42000, 15000, 'szt.', 1,  1, 8,    NULL, 'kwiaty', 1, 1),
(2,  1, 1, 'butonierka',    'OF-PM-02',  1, 'Butonierka pana młodego',                   4500,  1200, 'szt.', 1,  1, 20,   NULL, 'kwiaty', 1, 2),
(3,  1, 1, 'druhny',        'OF-PM-03',  1, 'Bukiety druhen',                           18000,  6000, 'szt.', 3,  0, 10,   'Mniejsze wersje Twojego bukietu spinają zdjęcia grupowe.', 'kwiaty', 1, 3),
(4,  1, 1, 'butonierki',    'OF-PM-04',  1, 'Butonierki świadków i ojców',               4500,  1200, 'szt.', 4,  0, NULL, 'Drobiazg, ale na zdjęciach rodzinnych od razu widać, kto jest z kim.', 'kwiaty', 1, 4),
(5,  1, 1, 'wianek',        'OF-PM-05',  1, 'Wianek do włosów',                         16000,  4500, 'szt.', 1,  0, 7,    'Z tych samych kwiatów co bukiet, robiony rano w dniu ślubu.', 'kwiaty', 1, 5),
(6,  1, 1, 'oltarz',        'OF-CER-01', 2, 'Dekoracja ołtarza, podstawowa',            65000, 23000, 'kpl.', 1,  1, 16,   NULL, 'kwiaty', 1, 6),
(7,  1, 1, 'lawki',         'OF-CER-02', 2, 'Dekoracja ławek',                           6000,  1800, 'szt.', 10, 0, 2,    'Co druga ławka wystarcza, żeby przejście wyglądało na pełne.', 'kwiaty', 1, 7),
(8,  1, 1, 'luk',           'OF-CER-03', 2, 'Łuk kwiatowy na ceremonię w plenerze',    240000, 82000, 'kpl.', 1,  0, 4,    'W plenerze łuk zastępuje ołtarz, a potem przenosimy go na salę jako tło do zdjęć.', 'kwiaty', 1, 8),
(9,  1, 1, 'platki',        'OF-CER-04', 2, 'Płatki dla dzieci',                         9000,  2500, 'kpl.', 1,  0, 17,   'Dzieci sypią płatki przed Wami — goście to uwielbiają.', 'kwiaty', 1, 9),
(10, 1, 1, 'przeniesienie', 'OF-CER-05', 2, 'Przeniesienie dekoracji z kościoła na salę', 38000, 16000, 'usł.', 1,  0, 5,    'Te same kwiaty pracują dwa razy: rano w kościele, wieczorem na sali.', 'kwiaty', 1, 10),
(11, 1, 1, 'stoly',         'OF-PRZ-01', 3, 'Kompozycja na stół gościnny, niska',       18000,  6000, 'szt.', 8,  1, 14,   NULL, 'kwiaty', 1, 11),
(12, 1, 1, 'prezydialny',   'OF-PRZ-02', 3, 'Girlanda na stół prezydialny',            120000, 38000, 'kpl.', 1,  0, 15,   'Wasz stół jest na co drugim zdjęciu z przyjęcia.', 'kwiaty', 1, 12),
(13, 1, 1, 'slodki',        'OF-PRZ-03', 3, 'Dekoracja słodkiego stołu',                65000, 19000, 'kpl.', 1,  0, NULL, 'Tort i ciasta stoją cały wieczór — kwiaty robią z nich osobne miejsce.', 'kwiaty', 1, 13),
(14, 1, 1, 'lustro',        'OF-PRZ-04', 3, 'Lustro selfie z kwiatami',                 85000, 24000, 'kpl.', 1,  0, NULL, 'Goście sami robią zdjęcia. To dekoracja, która pracuje przez cały wieczór.', 'kwiaty', 1, 14),
(15, 1, 1, 'scianka',       'OF-PRZ-05', 3, 'Ścianka kwiatowa',                        280000, 95000, 'kpl.', 1,  0, 6,    'Jedno miejsce, w którym każdy chce mieć zdjęcie z Wami.', 'kwiaty', 1, 15),
(16, 1, 1, 'ksiega',        'OF-PRZ-06', 3, 'Stojak na księgę gości z kwiatami',        24000,  7000, 'kpl.', 1,  0, NULL, 'Mały akcent przy wejściu, goście od razu wiedzą, gdzie się wpisać.', 'kwiaty', 1, 16),
(17, 1, 1, 'lampiony',      'OF-PRZ-07', 3, 'Świece i lampiony, wynajem',                1200,   300, 'szt.', 30, 0, NULL, 'Wieczorem to światło robi nastrój.', 'kwiaty', 1, 17),
(18, 1, 1, 'dojazd',        'OF-USL-01', 4, 'Dojazd do 30 km od pracowni',              12000,  6000, 'usł.', 1,  1, NULL, NULL, 'transport', 1, 18),
(19, 1, 1, 'florystka',     'OF-USL-02', 4, 'Florystka na miejscu, 8 h',                76000, 42000, 'usł.', 1,  0, NULL, 'Ktoś od nas pilnuje dekoracji przez cały dzień i poprawia, co trzeba.', 'personel', 1, 19),
(20, 1, 1, 'demontaz',      'OF-USL-03', 4, 'Demontaż po weselu',                       45000, 22000, 'usł.', 1,  0, NULL, 'Rano po weselu nie musicie nic sprzątać — zabieramy wszystko sami.', 'personel', 1, 20);

INSERT IGNORE INTO of_catalogue_upgrades (id, item_id, name, price_gr, cost_gr, photo_media_id, pitch) VALUES
(1, 1,  'Bukiet z piwonią i różą ogrodową',     60000,  21000, 9,    'Piwonie są w czerwcu w szczycie. Bukiet będzie pełniejszy i dłużej trzyma formę.'),
(2, 6,  'Dekoracja ołtarza, rozbudowana',      135000,  43000, 16,   'Dwie duże kompozycje po bokach zamiast jednej. To tło każdego zdjęcia z ceremonii.'),
(3, 7,  'Ławki z girlandą z zieleni',           11000,   3400, 2,    'Girlanda łączy ławki w jedną linię aż do ołtarza.'),
(4, 11, 'Kompozycja wysoka, na wazonie 40 cm',  32000,  10500, 3,    'Wysokie kompozycje nie zasłaniają gości przy stole, a sala wygląda na wyższą.'),
(5, 17, 'Wysokie lampiony ze świecą w szkle',    2200,    600, NULL, 'Wyższy płomień widać z każdego miejsca na sali.'),
(6, 18, 'Dojazd 30–60 km',                      28000,  13000, NULL, 'Sala jest dalej niż 30 km, więc liczymy drugi kurs busa.');

-- 4.2 Pakiety. Décision 4 : fixed_price_gr NULL = somme des positions
-- (pakiet bazowy : 42000 + 4500 + 65000 + 8 × 18000 + 12000 = 267 500 gr).
INSERT IGNORE INTO of_packages (id, tenant_id, brand_id, code, label, fixed_price_gr, is_default, sort) VALUES
(1, 1, 1, 'bazowy',   'Pakiet bazowy ola flora',  NULL, 0, 0),
(2, 1, 1, 'kamelia',  'Kamelia — podstawowy',     NULL, 0, 1),
(3, 1, 1, 'piwonia',  'Piwonia — pełny',          NULL, 1, 2),
(4, 1, 1, 'magnolia', 'Magnolia — rozbudowany',   NULL, 0, 3);

-- Kreator : « ołtarz 1 350 zł » = wersja rozbudowana (use_upgrade = 1),
-- « brama / ścianka 2 400 zł » = łuk kwiatowy (OF-CER-03).
INSERT IGNORE INTO of_package_items (id, package_id, item_id, qty, use_upgrade) VALUES
-- bazowy
(1,  1, 1,  1,  0), (2,  1, 2,  1, 0), (3,  1, 6,  1, 0), (4,  1, 11, 8, 0), (5,  1, 18, 1, 0),
-- kamelia
(6,  2, 1,  1,  0), (7,  2, 4,  2, 0), (8,  2, 6,  1, 1), (9,  2, 11, 6, 0),
-- piwonia
(10, 3, 1,  1,  0), (11, 3, 4,  6, 0), (12, 3, 6,  1, 1), (13, 3, 11, 12, 0), (14, 3, 8, 1, 0),
-- magnolia
(15, 4, 1,  2,  0), (16, 4, 4, 10, 0), (17, 4, 6,  2, 1), (18, 4, 11, 18, 0), (19, 4, 8, 1, 0);

-- ---------------------------------------------------------------------
-- 4.3 Dostawcy i zasoby
-- ---------------------------------------------------------------------

INSERT IGNORE INTO of_suppliers (id, tenant_id, slug, name, kind, notes, active) VALUES
(1, 1, 'vitro',            'Vitro Hurt',               'wynajem', 'Dowynajem: wazony szklane, lampiony.', 1),
(2, 1, 'dekor-serwis',     'Dekor Serwis',             'wynajem', 'Dowynajem: świeczniki.', 1),
(3, 1, 'rent-event',       'Rent&Event',               'wynajem', 'Dowynajem: stelaże łukowe.', 1),
(4, 1, 'ceramika-bo',      'Ceramika Bo',              'wynajem', 'Dowynajem: urny ceramiczne.', 1),
(5, 1, 'stolarnia-rzepka', 'Stolarnia Rzepka',         'wynajem', 'Dowynajem: podstawy drewniane.', 1),
(6, 1, 'bronisze',         'Hurt Kwiatowy Bronisze',   'kwiaty',  'Także zapisany jako „Bronisze”.', 1),
(7, 1, 'zielen-marek',     'Zieleń Marek',             'zielen',  NULL, 1),
(8, 1, 'florex',           'Florex',                   'kwiaty',  NULL, 1);

INSERT IGNORE INTO of_resources
  (id, tenant_id, brand_id, code, kind, name, unit_desc, stock_qty, price_block_gr, price_hour_gr,
   own_cost_hour_gr, ext_rent_cost_gr, supplier_id, default_mode, active, sort)
VALUES
-- sprzęt
(1,  1, NULL, 'waza40',    'sprzet',    'Wazon szklany 40 cm',            'szt. · wysoki, przezroczysty', 8,  1200,  300,  200,  900, 1, 'blok', 1, 1),
(2,  1, NULL, 'swiecznik', 'sprzet',    'Świecznik żeliwny',              'szt. · niski, czarny',         24, 800,   200,  100,  600, 2, 'blok', 1, 2),
(3,  1, NULL, 'stelaz',    'sprzet',    'Stelaż łukowy 2,4 m',            'kpl. · z podstawami',          2,  18000, 4000, 2000, 14000, 3, 'blok', 1, 3),
(4,  1, NULL, 'urna',      'sprzet',    'Urna ceramiczna',                'szt. · beż, 35 cm',            6,  2200,  600,  300,  1800, 4, 'blok', 1, 4),
(5,  1, NULL, 'lampion',   'sprzet',    'Lampion szklany',                'szt. · na świecę pieńkową',    30, 600,   200,  100,  500, 1, 'blok', 1, 5),
(6,  1, NULL, 'podstawa',  'sprzet',    'Podstawa drewniana',             'szt. · dąb, 40 × 40',          10, 1500,  400,  200,  1100, 5, 'blok', 1, 6),
-- transport
(7,  1, NULL, 'bus',       'transport', 'Bus 9-osobowy z kierowcą',       NULL, NULL, 64000, 9500,  5800, NULL, NULL, 'blok', 1, 1),
(8,  1, NULL, 'laweta',    'transport', 'Przyczepa na konstrukcje',       NULL, NULL, 28000, 4500,  2600, NULL, NULL, 'blok', 1, 2),
(9,  1, NULL, 'kurier',    'transport', 'Drugi kurs — świeże kwiaty',     NULL, NULL, 32000, 7000,  4000, NULL, NULL, 'godz', 1, 3),
-- personel
(10, 1, NULL, 'florystka', 'personel',  'Florystka',                      NULL, NULL, 78000, 9500,  5200, NULL, NULL, 'godz', 1, 1),
(11, 1, NULL, 'asystent',  'personel',  'Asystent montażu',               NULL, NULL, 52000, 6500,  3800, NULL, NULL, 'godz', 1, 2),
(12, 1, NULL, 'kierownik', 'personel',  'Kierownik montażu',              NULL, NULL, 98000, 12000, 7000, NULL, NULL, 'blok', 1, 3),
(13, 1, NULL, 'demontaz',  'personel',  'Obsługa demontażu (noc)',        NULL, NULL, 60000, 8500,  4800, NULL, NULL, 'godz', 1, 4);

-- ---------------------------------------------------------------------
-- 4.9 Zmienne i szablony maili — un seul système, syntaxe {{zmienna}}
-- (variables de la Poczta {x} renommées : {imiona}→{{para}},
--  {data}→{{data_wydarzenia}}, {telefon pracowni}→{{telefon}} …)
-- ---------------------------------------------------------------------

INSERT IGNORE INTO of_doc_variables (id, scope, vkey, description, example) VALUES
-- dokumenty (DOC_VARS)
(1,  'doc',  'para',            'Imiona zamawiających',          'Anna i Marek'),
(2,  'doc',  'data_wydarzenia', 'Data wesela lub dostawy',       '12 czerwca 2027'),
(3,  'doc',  'miejsce',         'Nazwa i adres miejsca',         'Pałac Ożarów Mazowiecki'),
(4,  'doc',  'numer_oferty',    'Numer dokumentu',               'OF-2027-014'),
(5,  'doc',  'suma',            'Suma oferty brutto',            '21 900 zł'),
(6,  'doc',  'zadatek',         'Kwota pierwszej raty',          '6 570 zł'),
(7,  'doc',  'zadatek_procent', 'Udział pierwszej raty',         '30%'),
(8,  'doc',  'raty',            'Tabela rat z terminami',        'I 30% · II 35% D-90 · III 35% D-14'),
(9,  'doc',  'termin_koncowy',  'Data płatności końcowej',       '29 maja 2027'),
(10, 'doc',  'waznosc_oferty',  'Data wygaśnięcia oferty',       '9 października 2026'),
(11, 'doc',  'link_tpay',       'Link do płatności',             NULL),
(12, 'doc',  'zespol',          'Liczba florystek i godziny',    '3 florystki, 14 h'),
(13, 'doc',  'kontakt',         'Mail i telefon pracowni',       NULL),
(14, 'doc',  'konto',           'Numer rachunku',                NULL),
-- maile (MAIL_VARS + Poczta VARS, ujednolicone)
(21, 'mail', 'para',            'Imiona zamawiających (pary) lub nazwa klienta', 'Anna i Marek'),
(22, 'mail', 'data_wydarzenia', 'Data wesela lub dostawy',       '12 czerwca 2027'),
(23, 'mail', 'miejsce',         'Nazwa i adres miejsca',         'Pałac Ożarów Mazowiecki'),
(24, 'mail', 'kwota',           'Suma brutto',                   '21 900 zł'),
(25, 'mail', 'zadatek',         'Kwota zadatku (rata I, 30%)',   '6 570 zł'),
(26, 'mail', 'waznosc',         'Data wygaśnięcia oferty',       '9 października 2026'),
(27, 'mail', 'link_tpay',       'Link do płatności',             NULL),
(28, 'mail', 'numer',           'Numer dokumentu / oferty',      'OF-2027-014'),
(29, 'mail', 'link_oferty',     'Link do oferty na żywo',        NULL),
(30, 'mail', 'termin',          'Termin płatności',              NULL),
(31, 'mail', 'podpis',          'Podpis marki',                  'Ola · Ola flora'),
(32, 'mail', 'telefon',         'Telefon pracowni',              NULL),
(33, 'mail', 'mail',            'E-mail pracowni',               NULL),
(34, 'mail', 'raty',            'Raty z terminami (II 35% D-90, III 35% D-14)', NULL);

INSERT IGNORE INTO of_mail_templates
  (id, tenant_id, brand_id, code, category, name, subject, body, default_attachments_json,
   trigger_note, is_system_default, default_subject, default_body, sort)
VALUES
(1, 1, 1, 'potwierdzenie_zapytania', 'zapytania', 'Potwierdzenie zapytania',
 'Dostałam Wasze zapytanie, {{data_wydarzenia}}',
 'Dzień dobry {{para}},\n\ndziękuję za wiadomość. Termin {{data_wydarzenia}} sprawdzam w kalendarzu i odezwę się w ciągu dwóch dni roboczych z propozycją rozmowy.\n\nJeśli macie zdjęcia z inspiracjami, odpiszcie na tego maila.\n\n{{podpis}}',
 '[]', 'automat, od razu po formularzu', 1,
 'Dostałam Wasze zapytanie, {{data_wydarzenia}}',
 'Dzień dobry {{para}},\n\ndziękuję za wiadomość. Termin {{data_wydarzenia}} sprawdzam w kalendarzu i odezwę się w ciągu dwóch dni roboczych z propozycją rozmowy.\n\nJeśli macie zdjęcia z inspiracjami, odpiszcie na tego maila.\n\n{{podpis}}', 1),
(2, 1, 1, 'termin_zajety', 'zapytania', 'Termin zajęty',
 'Termin {{data_wydarzenia}} jest już zajęty',
 'Dzień dobry {{para}},\n\nbardzo dziękuję za zapytanie. Niestety {{data_wydarzenia}} mam już komplet wesel i nie mogę przyjąć kolejnego.\n\nJeśli termin jest jeszcze do zmiany, chętnie sprawdzę sąsiednie soboty.\n\n{{podpis}}',
 '[]', 'ręcznie, „Odmów uprzejmie”', 1,
 'Termin {{data_wydarzenia}} jest już zajęty',
 'Dzień dobry {{para}},\n\nbardzo dziękuję za zapytanie. Niestety {{data_wydarzenia}} mam już komplet wesel i nie mogę przyjąć kolejnego.\n\nJeśli termin jest jeszcze do zmiany, chętnie sprawdzę sąsiednie soboty.\n\n{{podpis}}', 2),
(3, 1, 1, 'oferta_weselna', 'oferty', 'Oferta weselna',
 'Wasza oferta — Ola flora',
 'Cześć {{para}},\n\nw załączniku oferta na {{data_wydarzenia}}, {{miejsce}}. Rozbiłam ją na cztery bloki: kwiaty, zespół, dojazd i wynajem sprzętu — żebyście widzieli, z czego składa się każda złotówka.\n\nSuma: {{kwota}}. Zadatek {{zadatek}} (30%) rezerwuje termin, pozostałe dwie raty po 35% płacicie 90 i 14 dni przed weselem.\n\nOferta jest ważna do {{waznosc}}. Jeśli coś chcecie zmienić — piszcie, poprawiam i wysyłam nową wersję.\n\nOla',
 '["oferta_pdf","umowa_pdf","link_tpay"]', 'ręcznie, z kreatora oferty', 1,
 'Wasza oferta — Ola flora',
 'Cześć {{para}},\n\nw załączniku oferta na {{data_wydarzenia}}, {{miejsce}}. Rozbiłam ją na cztery bloki: kwiaty, zespół, dojazd i wynajem sprzętu — żebyście widzieli, z czego składa się każda złotówka.\n\nSuma: {{kwota}}. Zadatek {{zadatek}} (30%) rezerwuje termin, pozostałe dwie raty po 35% płacicie 90 i 14 dni przed weselem.\n\nOferta jest ważna do {{waznosc}}. Jeśli coś chcecie zmienić — piszcie, poprawiam i wysyłam nową wersję.\n\nOla', 3),
(4, 1, 1, 'oferta_link_na_zywo', 'oferty', 'Oferta weselna — link na żywo',
 'Wasza oferta, Ola flora ({{numer}})',
 'Dzień dobry {{para}},\n\nw załączniku oferta dekoracji na {{data_wydarzenia}}, {{miejsce}}. Całość: {{kwota}}.\n\nOfertę możecie też oglądać na żywo pod linkiem: {{link_oferty}}\nTermin rezerwuję po wpłacie zadatku {{zadatek}}.\n\n{{podpis}}',
 '["oferta_pdf","link_tpay"]', 'ręcznie, z Poczty / asystenta rozmowy', 1,
 'Wasza oferta, Ola flora ({{numer}})',
 'Dzień dobry {{para}},\n\nw załączniku oferta dekoracji na {{data_wydarzenia}}, {{miejsce}}. Całość: {{kwota}}.\n\nOfertę możecie też oglądać na żywo pod linkiem: {{link_oferty}}\nTermin rezerwuję po wpłacie zadatku {{zadatek}}.\n\n{{podpis}}', 4),
(5, 1, 1, 'oferta_kreator', 'oferty', 'Wysyłka oferty — treść z kreatora',
 'Wasza oferta, Ola flora ({{numer}})',
 'Cześć {{para}},\n\nwysyłamy Waszą ofertę. Klikając link niżej widzicie ją na żywo — możecie włączać i wyłączać opcje, suma przelicza się od razu.\n\nZadatek {{zadatek}} opłacacie przez Tpay, dwie kolejne raty (po 35%) — 90 i 14 dni przed terminem.\n\nOla',
 '["oferta_pdf","link_tpay"]', 'domyślna treść okna „Wyślij ofertę” w kreatorze', 1,
 'Wasza oferta, Ola flora ({{numer}})',
 'Cześć {{para}},\n\nwysyłamy Waszą ofertę. Klikając link niżej widzicie ją na żywo — możecie włączać i wyłączać opcje, suma przelicza się od razu.\n\nZadatek {{zadatek}} opłacacie przez Tpay, dwie kolejne raty (po 35%) — 90 i 14 dni przed terminem.\n\nOla', 5),
(6, 1, 1, 'przypomnienie_oferta', 'oferty', 'Przypomnienie o ofercie',
 'Czy oferta na {{data_wydarzenia}} jest aktualna?',
 'Dzień dobry {{para}},\n\nwracam do oferty {{numer}}. Trzymam dla Was termin jeszcze przez kilka dni. Jeśli coś chcecie zmienić, napiszcie, poprawię.\n\n{{podpis}}',
 '["oferta_pdf"]', 'automat, D+3 od wysłania oferty (oferta bez odpowiedzi)', 1,
 'Czy oferta na {{data_wydarzenia}} jest aktualna?',
 'Dzień dobry {{para}},\n\nwracam do oferty {{numer}}. Trzymam dla Was termin jeszcze przez kilka dni. Jeśli coś chcecie zmienić, napiszcie, poprawię.\n\n{{podpis}}', 6),
(7, 1, 1, 'przypomnienie_zadatek', 'platnosci', 'Przypomnienie o zadatku',
 'Zadatek {{zadatek}} do {{termin}}',
 'Dzień dobry {{para}},\n\nprzypominam o zadatku {{zadatek}} za dekoracje na {{data_wydarzenia}}. Termin płatności: {{termin}}.\n\nLink do płatności: {{link_tpay}}\n\n{{podpis}}',
 '["link_tpay"]', 'automat, +1 dzień od akceptacji oferty', 1,
 'Zadatek {{zadatek}} do {{termin}}',
 'Dzień dobry {{para}},\n\nprzypominam o zadatku {{zadatek}} za dekoracje na {{data_wydarzenia}}. Termin płatności: {{termin}}.\n\nLink do płatności: {{link_tpay}}\n\n{{podpis}}', 7),
(8, 1, 1, 'przypomnienie_zadatek_termin', 'platnosci', 'Przypomnienie o zadatku — trzymany termin',
 'Zadatek za {{data_wydarzenia}} — przypomnienie',
 'Cześć {{para}},\n\nTermin {{data_wydarzenia}} trzymam dla Was do {{waznosc}}. Zadatek {{zadatek}} można zapłacić tym linkiem: {{link_tpay}}.\n\nPo zaksięgowaniu dostaniecie potwierdzenie i wchodzimy w projekt dekoracji.\n\nOla',
 '["link_tpay"]', 'ręcznie / automat (Parametry: D-7 od akceptacji)', 1,
 'Zadatek za {{data_wydarzenia}} — przypomnienie',
 'Cześć {{para}},\n\nTermin {{data_wydarzenia}} trzymam dla Was do {{waznosc}}. Zadatek {{zadatek}} można zapłacić tym linkiem: {{link_tpay}}.\n\nPo zaksięgowaniu dostaniecie potwierdzenie i wchodzimy w projekt dekoracji.\n\nOla', 8),
(9, 1, 1, 'potwierdzenie_platnosci', 'platnosci', 'Potwierdzenie płatności',
 'Wpłata zaksięgowana, {{numer}}',
 'Dzień dobry {{para}},\n\npotwierdzam wpłatę {{kwota}}. Faktura w załączniku. Termin {{data_wydarzenia}} jest Wasz.\n\n{{podpis}}',
 '["faktura_pdf"]', 'automat, po zaksięgowaniu zadatku (Tpay)', 1,
 'Wpłata zaksięgowana, {{numer}}',
 'Dzień dobry {{para}},\n\npotwierdzam wpłatę {{kwota}}. Faktura w załączniku. Termin {{data_wydarzenia}} jest Wasz.\n\n{{podpis}}', 9),
(10, 1, 1, 'potwierdzenie_platnosci_tpay', 'platnosci', 'Potwierdzenie płatności Tpay',
 'Płatność zaksięgowana — dziękujemy',
 'Dzień dobry,\n\nPłatność {{kwota}} została zaksięgowana. Zamówienie na {{data_wydarzenia}} jest potwierdzone.\n\nFaktura idzie osobnym mailem w ciągu dwóch dni roboczych.\n\nDziękujemy.',
 '[]', 'automat, po zaksięgowaniu raty II i III', 1,
 'Płatność zaksięgowana — dziękujemy',
 'Dzień dobry,\n\nPłatność {{kwota}} została zaksięgowana. Zamówienie na {{data_wydarzenia}} jest potwierdzone.\n\nFaktura idzie osobnym mailem w ciągu dwóch dni roboczych.\n\nDziękujemy.', 10),
(11, 1, 1, 'plan_dnia', 'realizacja', 'Plan dnia przed weselem',
 'Plan montażu na {{data_wydarzenia}}',
 'Dzień dobry {{para}},\n\nw załączniku plan dnia. Przyjeżdżamy na {{miejsce}} o 9:00, kończymy montaż przed przyjazdem gości.\n\nKontakt w dniu ślubu: {{telefon}}.\n\n{{podpis}}',
 '["retroplan_pdf"]', 'automat, D-5 przed weselem, 09:00', 1,
 'Plan montażu na {{data_wydarzenia}}',
 'Dzień dobry {{para}},\n\nw załączniku plan dnia. Przyjeżdżamy na {{miejsce}} o 9:00, kończymy montaż przed przyjazdem gości.\n\nKontakt w dniu ślubu: {{telefon}}.\n\n{{podpis}}', 11);

-- ---------------------------------------------------------------------
-- 4.4 Automaty i relanse (AUTO + RULES0 scalone, une seule table)
-- ---------------------------------------------------------------------

INSERT IGNORE INTO of_automation_rules
  (id, tenant_id, brand_id, code, sort, trigger_type, trigger_stage, system_event, offset_days, send_time,
   channel, template_id, sms_body, description, active)
VALUES
(1,  1, 1, 'form_ack',            1,  'system_event', NULL,                   'form_submitted', 0,   NULL,
     'mail', 1,    NULL, 'Nowe zgłoszenie z formularza → potwierdzenie zapytania, od razu.', 1),
(2,  1, 1, 'zapytanie_sms_d1',    2,  'stage_enter',  'zapytanie',            NULL,             1,   NULL,
     'sms',  NULL, 'Dzień dobry, odebraliśmy zapytanie. Kiedy możemy zadzwonić?', 'Dzień dobry, odebraliśmy zapytanie. Kiedy możemy zadzwonić?', 1),
(3,  1, 1, 'rozmowa_mail_d2',     3,  'stage_enter',  'rozmowa',              NULL,             2,   NULL,
     'mail', NULL, NULL, 'Podsumowanie rozmowy i termin przesłania oferty.', 1),
(4,  1, 1, 'oferta_mail_d3',      4,  'stage_enter',  'oferta_wyslana',       NULL,             3,   NULL,
     'mail', 6,    NULL, 'Przypomnienie o ofercie, link na żywo w mailu (scala „oferta bez odpowiedzi” z Poczty).', 1),
(5,  1, 1, 'oferta_sms_wygasa',   5,  'stage_enter',  'oferta_wyslana',       NULL,             23,  NULL,
     'sms',  NULL, 'Oferta wygasa za 7 dni — pytania?', 'Oferta wygasa za 7 dni — pytania? (D+23 przy ważności 30 dni)', 1),
(6,  1, 1, 'akcept_mail_d0',      6,  'stage_enter',  'oferta_zaakceptowana', NULL,             0,   NULL,
     'mail', NULL, NULL, 'Umowa i link Tpay na zadatek 30%.', 1),
(7,  1, 1, 'akcept_zadatek_d1',   7,  'system_event', NULL,                   'offer_accepted', 1,   NULL,
     'mail+sms', 7, 'Przypomnienie o zadatku, link Tpay.', 'Oferta zaakceptowana → przypomnienie o zadatku, +1 dzień.', 1),
(8,  1, 1, 'akcept_sms_d4',       8,  'stage_enter',  'oferta_zaakceptowana', NULL,             4,   NULL,
     'sms',  NULL, 'Przypomnienie o zadatku, link Tpay.', 'Przypomnienie o zadatku, link Tpay.', 1),
(9,  1, 1, 'zadatek_potwierdzenie', 9, 'system_event', NULL,                  'deposit_booked', 0,   NULL,
     'mail', 9,    NULL, 'Zadatek zaksięgowany (Tpay) → potwierdzenie płatności, od razu.', 1),
(10, 1, 1, 'rata_potwierdzenie',  10, 'system_event', NULL,                   'payment_booked', 0,   NULL,
     'mail', 10,   NULL, 'Rata II / III zaksięgowana → potwierdzenie płatności Tpay.', 1),
(11, 1, 1, 'doplata_mail_d21',    11, 'event_offset', 'zadatek_oplacony',     NULL,             -21, NULL,
     'mail', NULL, NULL, 'Dopłata i potwierdzenie szczegółów montażu.', 1),
(12, 1, 1, 'doplata_sms_d14',     12, 'event_offset', 'zadatek_oplacony',     NULL,             -14, NULL,
     'sms',  NULL, 'Ostatnie przypomnienie o dopłacie.', 'Ostatnie przypomnienie o dopłacie.', 1),
(13, 1, 1, 'plan_dnia_d5',        13, 'event_offset', NULL,                   NULL,             -5,  '09:00:00',
     'mail', 11,   NULL, 'Na 5 dni przed weselem → plan dnia przed weselem.', 1),
(14, 1, 1, 'podziekowanie_d2',    14, 'stage_enter',  'zrealizowane',         NULL,             2,   NULL,
     'mail', NULL, NULL, 'Podziękowanie, zdjęcia i prośba o opinię.', 1);

-- ---------------------------------------------------------------------
-- 4.10 Dokumenty, raty (décision 3 : 30 / 35 / 35), klauzule
-- ---------------------------------------------------------------------

INSERT IGNORE INTO of_document_types (id, tenant_id, brand_id, code, name, description, send_when, enabled, sort) VALUES
(1, 1, 1, 'potw',    'Potwierdzenie zapytania', 'Streszczenie 12 odpowiedzi z formularza, bez ceny',     'od razu po formularzu', 1, 1),
(2, 1, 1, 'oferta',  'Oferta PDF',              'Wycena pozycja po pozycji, harmonogram, link do zadatku', 'po rozmowie',           1, 2),
(3, 1, 1, 'umowa',   'Umowa o dekorację',       'Załącznik do oferty: strony, raty, klauzule, podpisy',   'z ofertą',              1, 3),
(4, 1, 1, 'faktura', 'Faktura / rachunek',      'Wystawiany po zaksięgowaniu każdej raty',                'po płatności',          1, 4);

INSERT IGNORE INTO of_installment_templates
  (id, tenant_id, brand_id, no_label, sort, label, pct, kind, due_rule, due_offset_days, grace_business_days)
VALUES
(1, 1, 1, 'I',   1, 'Zadatek przy podpisaniu umowy, płatny w 3 dni roboczych', 30.00, 'zadatek', 'przy_podpisie', NULL, 3),
(2, 1, 1, 'II',  2, 'Rata druga',                                              35.00, 'rata',    'D-90',          -90,  NULL),
(3, 1, 1, 'III', 3, 'Płatność końcowa',                                        35.00, 'koncowa', 'D-14',          -14,  NULL);

INSERT IGNORE INTO of_contract_clauses (id, tenant_id, brand_id, sort, ckey, title, pdf_title, body, enabled) VALUES
(1,  1, 1, 1,  'c1',  'Rezerwacja terminu po zaksięgowaniu zadatku', 'Rezerwacja terminu.',
 'Termin zostaje zablokowany w kalendarzu wykonawcy w dniu zaksięgowania zadatku. Do tego czasu data pozostaje wolna.', 1),
(2,  1, 1, 2,  'c2',  'Zmiany w zakresie do 30 dni przed terminem', 'Zmiany w zakresie.',
 'Do 30 dni przed terminem bez opłat, na podstawie aneksu mailowego. Później możliwe wyłącznie dodatki; zmniejszenie zakresu nie obniża wynagrodzenia.', 1),
(3,  1, 1, 3,  'c3',  'Zmiana liczby gości i aktualizacja wyceny', 'Liczba gości.',
 'Zmiana liczby stołów o więcej niż dwa wymaga aktualizacji wyceny. Wykonawca przedstawia ją w 3 dni roboczych.', 1),
(4,  1, 1, 4,  'c4',  'Rezygnacja zamawiającego: progi 70% i 100%', 'Rezygnacja zamawiającego.',
 'Zadatek nie podlega zwrotowi: pokrywa rezerwację terminu i zamówienia u hodowców. Rezygnacja później niż 30 dni przed terminem oznacza zapłatę 70% wynagrodzenia, później niż 14 dni: 100%.', 1),
(5,  1, 1, 5,  'c5',  'Odstąpienie wykonawcy i zwrot wpłat', 'Odstąpienie wykonawcy.',
 'W razie niemożności realizacji z winy wykonawcy zwraca on wszystkie wpłacone kwoty w 7 dni i pomaga znaleźć zastępstwo.', 1),
(6,  1, 1, 6,  'c6',  'Sezonowość kwiatów i dobór zamienników', 'Dostępność kwiatów.',
 'Kwiaty są towarem sezonowym. Przy braku odmiany u hodowców wykonawca dobiera najbliższą kolorystycznie i kształtem, w tej samej cenie, informując zamawiającego przed zakupem.', 1),
(7,  1, 1, 7,  'c7',  'Sprzęt w wynajmie, braki i uszkodzenia', 'Sprzęt w wynajmie.',
 'Wynajęty sprzęt pozostaje własnością wykonawcy i wraca po demontażu. Braki i uszkodzenia rozliczane są po weselu według cennika wynajmu.', 1),
(8,  1, 1, 8,  'c8',  'Dostęp do miejsca w godzinach montażu', 'Dostęp do miejsca.',
 'Zamawiający zapewnia dostęp do sali i miejsca ceremonii w godzinach montażu oraz miejsce do rozładunku. Opóźnienie po stronie obiektu nie obciąża wykonawcy.', 1),
(9,  1, 1, 9,  'c9',  'Siła wyższa i przeniesienie terminu', 'Siła wyższa.',
 'Przy zdarzeniach niezależnych od stron (zakaz zgromadzeń, klęska żywiołowa) strony ustalają nowy termin w ciągu 14 dni. Wpłacone kwoty przechodzą na nowy termin.', 1),
(10, 1, 1, 10, 'c10', 'Zdjęcia realizacji w portfolio', 'Zdjęcia realizacji.',
 'Wykonawca może publikować zdjęcia dekoracji w portfolio i mediach społecznościowych, bez wizerunku gości, o ile zamawiający nie zgłosi sprzeciwu mailem.', 1),
(11, 1, 1, 11, 'c11', 'Dane osobowe i okres przechowywania', 'Dane osobowe.',
 'Dane przetwarzane są wyłącznie w celu realizacji umowy i rozliczeń, przez okres wymagany przepisami podatkowymi.', 1),
(12, 1, 1, 12, 'c12', 'Spory i właściwość sądu', 'Spory.',
 'Strony dążą do porozumienia. W braku ugody sprawę rozstrzyga sąd właściwy dla siedziby wykonawcy. W sprawach nieuregulowanych stosuje się Kodeks cywilny.', 1);

-- ---------------------------------------------------------------------
-- 4.5 Retroplan — un seul modèle = RETRO_TASKS ∪ RELANS, trié par offset
-- ---------------------------------------------------------------------

INSERT IGNORE INTO of_retro_templates (id, tenant_id, brand_id, name, is_default) VALUES
(1, 1, 1, 'Wesele — retroplan standardowy', 1);

INSERT IGNORE INTO of_retro_template_steps (id, template_id, sort, offset_days, name, default_assignee, channel, kind) VALUES
(1,  1, 1,  -300, 'Rozmowa i wysłanie oferty',            'właścicielka',      NULL,         'zadanie'),
(2,  1, 2,  -180, 'Umowa i zadatek zaksięgowany',         'właścicielka',      NULL,         'platnosc'),
(3,  1, 3,  -120, 'Wysyłka oferty i umowy',               NULL,                'mail',       'relanca'),
(4,  1, 4,   -90, 'Zamówienie kwiatów w hurtowni',        'właścicielka',      NULL,         'zadanie'),
(5,  1, 5,   -90, 'Rata druga: przypomnienie',            NULL,                'mail',       'platnosc'),
(6,  1, 6,   -45, 'Wizja lokalna w miejscu przyjęcia',    NULL,                'spotkanie',  'zadanie'),
(7,  1, 7,   -30, 'Potwierdzenie składu zespołu',         'kierownik montażu', NULL,         'zadanie'),
(8,  1, 8,   -30, 'Zamknięcie zakresu i listy sprzętu',   NULL,                'mail',       'relanca'),
(9,  1, 9,   -14, 'Rezerwacja dowynajmu sprzętu',         'kierownik montażu', NULL,         'zadanie'),
(10, 1, 10,  -14, 'Płatność końcowa',                     NULL,                'mail + sms', 'platnosc'),
(11, 1, 11,   -7, 'Lista załadunku i karty misji',        'kierownik montażu', NULL,         'zadanie'),
(12, 1, 12,   -7, 'Zamówienie kwiatów u hodowców',        NULL,                'wewnetrzne', 'zadanie'),
(13, 1, 13,   -2, 'Zakup kwiatów, wstawienie do chłodni', 'florystka',         NULL,         'zadanie'),
(14, 1, 14,   -2, 'Potwierdzenie godzin montażu',         NULL,                'telefon',    'relanca'),
(15, 1, 15,    0, 'Montaż na miejscu',                    'cały zespół',       NULL,         'zadanie'),
(16, 1, 16,    1, 'Demontaż i zwrot sprzętu',             'transport i montaż', NULL,        'zadanie');

-- ---------------------------------------------------------------------
-- 4.6 Cartes de mission — checklists de base par rôle
-- ---------------------------------------------------------------------

INSERT IGNORE INTO of_mission_templates
  (id, tenant_id, role, name, sort, window_start, window_end, meet_time, meet_place, transport, kit_text)
VALUES
(1, 1, 'kierownik montażu', 'Checklista bazowa — kierownik montażu', 1, '07:30:00', '16:00:00', '07:30:00', 'atelier',
 'Bus 9-os. (prowadzi transport)', '1× stelaż łukowy 2,4 m\n12× wazon szklany 40 cm\n2× podstawa dębowa'),
(2, 1, 'florystka', 'Checklista bazowa — florystka', 2, '06:00:00', '14:00:00', '06:00:00', 'chłodnia',
 'Bus 9-os.', '24× lampion szklany\nskrzynki transportowe'),
(3, 1, 'transport i montaż', 'Checklista bazowa — transport i montaż', 3, '07:00:00', '17:00:00', '07:00:00', 'garaż',
 'Bus + przyczepa na konstrukcje', 'przyczepa na konstrukcje\npasy\n2× drabina'),
(4, 1, 'obsługa demontażu', 'Checklista bazowa — obsługa demontażu', 4, '22:00:00', '01:00:00', '22:00:00', 'na miejscu',
 'Bus 9-os.', 'zwrot całego sprzętu do magazynu');

INSERT IGNORE INTO of_mission_template_tasks (id, template_id, sort, at_time, name) VALUES
(1,  1, 1, '08:00:00', 'Odbiór kluczy i wejście na obiekt'),
(2,  1, 2, '08:40:00', 'Rozstawienie stelaża przy ołtarzu'),
(3,  1, 3, '10:15:00', 'Kontrola bukietu panny młodej'),
(4,  1, 4, '11:30:00', 'Zdjęcia dekoracji do portfolio'),
(5,  1, 5, '12:00:00', 'Podpis pary na protokole'),
(6,  2, 1, '06:00:00', 'Wyjęcie kwiatów z chłodni'),
(7,  2, 2, '07:00:00', 'Bukiet i butonierki'),
(8,  2, 3, '09:30:00', 'Kompozycje na 12 stołów'),
(9,  2, 4, '11:00:00', 'Załadunek do busa'),
(10, 3, 1, '07:00:00', 'Sprawdzenie przyczepy i pasów'),
(11, 3, 2, '07:20:00', 'Załadunek stelaża i podstaw'),
(12, 3, 3, '08:00:00', 'Dojazd 14 km'),
(13, 3, 4, '13:00:00', 'Drugi kurs po świeże kwiaty'),
(14, 4, 1, '22:15:00', 'Demontaż stelaża'),
(15, 4, 2, '23:00:00', 'Spis sprzętu — braki i uszkodzenia'),
(16, 4, 3, '00:30:00', 'Zwrot do magazynu');

-- ---------------------------------------------------------------------
-- 4.8 Scénario de l'assistant d'appel (décision 4 : base 267 500 gr)
-- ---------------------------------------------------------------------

INSERT IGNORE INTO of_call_scripts (id, tenant_id, brand_id, name, base_package_id, base_price_gr, is_default) VALUES
(1, 1, 1, 'Rozmowa weselna — domyślny', 1, 267500, 1);

INSERT IGNORE INTO of_call_script_steps (id, script_id, sort, skey, kind, title, minutes, say_text) VALUES
(1, 1, 1, 's1', 'intro',   'dzień dobry',      2,
 'Dzień dobry, tu Ola z Ola flora. Dziękuję, że znaleźliście czas. Rozmowa zajmie około pół godziny, a na końcu będziecie wiedzieć, co dostajecie i za ile.'),
(2, 1, 2, 's2', 'talk',    'przedstawienie',   5,
 'Robimy tylko wesela. Najpierw projekt dekoracji, potem zadatek 30%, a w dniu ślubu przyjeżdżamy, montujemy i wychodzimy, zanim przyjdą goście.'),
(3, 1, 3, 's3', 'budget',  'budżet',           3,
 'Jaki budżet przeznaczacie na kwiaty i dekoracje? Nie musi być dokładny — pomoże mi dobrać to, co ma sens.'),
(4, 1, 4, 's4', 'library', 'dobór dekoracji',  15,
 'W pakiecie bazowym macie już bukiet, ołtarz i stoły. Z resztą budżetu możemy zrobić kilka rzeczy — pokażę, co daje najwięcej na zdjęciach.'),
(5, 1, 5, 's5', 'summary', 'podsumowanie',     5,
 'Podsumuję, co wybraliśmy. Ofertę z linkiem do zadatku wyślę dziś do 18:00, a termin trzymam dla Was przez 7 dni.');

INSERT IGNORE INTO of_call_script_points (id, step_id, sort, text) VALUES
(1, 1, 1, 'Potwierdzić imiona pary'),
(2, 1, 2, 'Potwierdzić datę i miejsce'),
(3, 1, 3, 'Zapytać, czy mogę robić notatki'),
(4, 2, 1, 'Pokazać 2–3 realizacje z portfolio'),
(5, 2, 2, 'Zapytać o styl i kolory'),
(6, 2, 3, 'Zapytać o liczbę gości i stołów'),
(7, 5, 1, 'Ustalić termin decyzji'),
(8, 5, 2, 'Potwierdzić mail do wysyłki');

INSERT IGNORE INTO of_call_script_step_items (step_id, catalogue_item_id, sort) VALUES
(2, 8, 1), (2, 15, 2), (2, 12, 3),   -- s2 : łuk, ścianka, prezydialny
(3, 1, 1), (3, 6, 2),  (3, 11, 3),   -- s3 : bukiet, ołtarz, stoły
(4, 14, 1), (4, 13, 2), (4, 19, 3);  -- s4 : lustro, słodki stół, florystka

-- ---------------------------------------------------------------------
-- 4.12 CMS — witryna „ola flora”
-- ---------------------------------------------------------------------

INSERT IGNORE INTO of_sites (id, tenant_id, brand_id, label, base_url, live) VALUES
(1, 1, 1, 'ola flora', 'https://olaflora.pl', 1);

INSERT IGNORE INTO of_pages (id, site_id, slug, label, tab_anchor, visible, sort) VALUES
(1, 1, 'home',      'strona główna',      'home',      1, 1),
(2, 1, 'oferta',    'oferta',             'oferta',    1, 2),
(3, 1, 'portfolio', 'portfolio',          'portfolio', 1, 3),
(4, 1, 'omnie',     'o mnie',             'omnie',     1, 4),
(5, 1, 'kontakt',   'kontakt',            'kontakt',   1, 5),
(6, 1, 'form',      'formularz (wizard)', 'form',      1, 6),
(7, 1, 'wspolne',   'wspólne · menu i stopka', '',     1, 0);

-- Sections : créées par le CMS (écran « strony » du back office) au premier
-- geste, une par texte / photo / liste de la page publique, sous la clé que
-- calcule adapt.js (« home:t1a2b3c4d », « portfolio:galeria »…). Rien à
-- poser ici : tant qu'une section n'est pas publiée, la page garde son texte.

-- ---------------------------------------------------------------------
-- 4.13 Realizacje — słowniki tagów
-- ---------------------------------------------------------------------

INSERT IGNORE INTO of_tags (id, tenant_id, group_code, label, sort) VALUES
(1,  1, 'typ', 'plener',          1),
(2,  1, 'typ', 'kościół',         2),
(3,  1, 'typ', 'pałac',           3),
(4,  1, 'typ', 'dwór',            4),
(5,  1, 'typ', 'stodoła',         5),
(6,  1, 'typ', 'sala hotelowa',   6),
(7,  1, 'typ', 'klasyczne',       7),
(8,  1, 'typ', 'boho',            8),
(9,  1, 'typ', 'minimalistyczne', 9),
(10, 1, 'typ', 'glamour',         10),
(21, 1, 'dekoracja', 'brama kwiatowa',     1),
(22, 1, 'dekoracja', 'łuk ceremonialny',   2),
(23, 1, 'dekoracja', 'instalacja wisząca', 3),
(24, 1, 'dekoracja', 'dekoracja ołtarza',  4),
(25, 1, 'dekoracja', 'stoły okrągłe',      5),
(26, 1, 'dekoracja', 'stoły prostokątne',  6),
(27, 1, 'dekoracja', 'stoły mieszane',     7),
(28, 1, 'dekoracja', 'stół prezydialny',   8),
(29, 1, 'dekoracja', 'bukiet panny młodej', 9),
(30, 1, 'dekoracja', 'butonierki',         10),
(31, 1, 'dekoracja', 'słodki stół',        11),
(32, 1, 'dekoracja', 'strefa tortu',       12),
(33, 1, 'dekoracja', 'plan sali',          13),
(34, 1, 'dekoracja', 'ścianka do zdjęć',   14);
