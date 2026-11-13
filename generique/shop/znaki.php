<?php
// ============================================================================
//  znaki.php — les dessins de la boutique : icônes des trois promesses,
//  pictogrammes du contact, logos des modes de livraison et de paiement.
//
//  TOUT EST DESSINÉ DANS LA PAGE, en SVG, pas chargé en fichier : pas de
//  requête de plus, pas d'image cassée si un fichier manque, et les icônes
//  prennent la couleur du thème (currentColor) — Wygląd les change avec le
//  reste.
//
//  LES MARQUES. Visa, Apple Pay, Google Pay et le symbole DPD viennent de
//  Simple Icons (simpleicons.org) : dessins publiés en CC0, vérifiés à
//  l'import — un seul tracé chacun, rien d'autre que des coordonnées.
//  Mastercard est construit sur sa géométrie publique : deux cercles et leur
//  intersection, aux trois couleurs de la marque. BLIK et InPost n'ont pas de
//  dessin libre : leur tuile est typographique, à leurs couleurs. Les marques
//  restent celles de leurs propriétaires ; elles ne servent ici qu'à dire au
//  client comment il peut payer et recevoir — l'usage même qu'elles attendent
//  d'un marchand.
// ============================================================================
declare(strict_types=1);

/** Tracés des marques : [viewBox recadré sur le dessin, tracé, couleur]. */
const WSM_ZNAK_PATHS = [
    'visa'      => ['0 7.9 24 8.2',   'M9.112 8.262L5.97 15.758H3.92L2.374 9.775c-.094-.368-.175-.503-.461-.658C1.447 8.864.677 8.627 0 8.479l.046-.217h3.3a.904.904 0 01.894.764l.817 4.338 2.018-5.102zm8.033 5.049c.008-1.979-2.736-2.088-2.717-2.972.006-.269.262-.555.822-.628a3.66 3.66 0 011.913.336l.34-1.59a5.207 5.207 0 00-1.814-.333c-1.917 0-3.266 1.02-3.278 2.479-.012 1.079.963 1.68 1.698 2.04.756.367 1.01.603 1.006.931-.005.504-.602.725-1.16.734-.975.015-1.54-.263-1.992-.473l-.351 1.642c.453.208 1.289.39 2.156.398 2.037 0 3.37-1.006 3.377-2.564m5.061 2.447H24l-1.565-7.496h-1.656a.883.883 0 00-.826.55l-2.909 6.946h2.036l.405-1.12h2.488zm-2.163-2.656l1.02-2.815.588 2.815zm-8.16-4.84l-1.603 7.496H8.34l1.605-7.496z',      '#1A1F71'],
    'applepay'  => ['0 4.1 24 15.8',  'M2.15 4.318a42.16 42.16 0 0 0-.454.003c-.15.005-.303.013-.452.04a1.44 1.44 0 0 0-1.06.772c-.07.138-.114.278-.14.43-.028.148-.037.3-.04.45A10.2 10.2 0 0 0 0 6.222v11.557c0 .07.002.138.003.207.004.15.013.303.04.452.027.15.072.291.142.429a1.436 1.436 0 0 0 .63.63c.138.07.278.115.43.142.148.027.3.036.45.04l.208.003h20.194l.207-.003c.15-.004.303-.013.452-.04.15-.027.291-.071.428-.141a1.432 1.432 0 0 0 .631-.631c.07-.138.115-.278.141-.43.027-.148.036-.3.04-.45.002-.07.003-.138.003-.208l.001-.246V6.221c0-.07-.002-.138-.004-.207a2.995 2.995 0 0 0-.04-.452 1.446 1.446 0 0 0-1.2-1.201 3.022 3.022 0 0 0-.452-.04 10.448 10.448 0 0 0-.453-.003zm0 .512h19.942c.066 0 .131.002.197.003.115.004.25.01.375.032.109.02.2.05.287.094a.927.927 0 0 1 .407.407.997.997 0 0 1 .094.288c.022.123.028.258.031.374.002.065.003.13.003.197v11.552c0 .065 0 .13-.003.196-.003.115-.009.25-.032.375a.927.927 0 0 1-.5.693 1.002 1.002 0 0 1-.286.094 2.598 2.598 0 0 1-.373.032l-.2.003H1.906c-.066 0-.133-.002-.196-.003a2.61 2.61 0 0 1-.375-.032c-.109-.02-.2-.05-.288-.094a.918.918 0 0 1-.406-.407 1.006 1.006 0 0 1-.094-.288 2.531 2.531 0 0 1-.032-.373 9.588 9.588 0 0 1-.002-.197V6.224c0-.065 0-.131.002-.197.004-.114.01-.248.032-.375.02-.108.05-.199.094-.287a.925.925 0 0 1 .407-.406 1.03 1.03 0 0 1 .287-.094c.125-.022.26-.029.375-.032.065-.002.131-.002.196-.003zm4.71 3.7c-.3.016-.668.199-.88.456-.191.22-.36.58-.316.918.338.03.675-.169.888-.418.205-.258.345-.603.308-.955zm2.207.42v5.493h.852v-1.877h1.18c1.078 0 1.835-.739 1.835-1.812 0-1.07-.742-1.805-1.808-1.805zm.852.719h.982c.739 0 1.161.396 1.161 1.089 0 .692-.422 1.092-1.164 1.092h-.979zm-3.154.3c-.45.01-.83.28-1.05.28-.235 0-.593-.264-.981-.257a1.446 1.446 0 0 0-1.23.747c-.527.908-.139 2.255.374 2.995.249.366.549.769.944.754.373-.014.52-.242.973-.242.454 0 .586.242.98.235.41-.007.667-.366.915-.733.286-.417.403-.82.41-.841-.007-.008-.79-.308-.797-1.209-.008-.754.615-1.113.644-1.135-.352-.52-.9-.578-1.09-.593a1.123 1.123 0 0 0-.092-.002zm8.204.397c-.99 0-1.606.533-1.652 1.256h.777c.072-.358.369-.586.845-.586.502 0 .803.266.803.711v.309l-1.097.064c-.951.054-1.488.484-1.488 1.184 0 .72.548 1.207 1.332 1.207.526 0 1.032-.281 1.264-.727h.019v.659h.788v-2.76c0-.803-.62-1.317-1.591-1.317zm1.94.072l1.446 4.009c0 .003-.073.24-.073.247-.125.41-.33.571-.711.571-.069 0-.206 0-.267-.015v.666c.06.011.267.019.335.019.83 0 1.226-.312 1.568-1.283l1.5-4.214h-.868l-1.012 3.259h-.015l-1.013-3.26zm-1.167 2.189v.316c0 .521-.45.917-1.024.917-.442 0-.731-.228-.731-.579 0-.342.278-.56.769-.593z',  '#000000'],
    'googlepay' => ['0 7 24 10',      'M3.963 7.235A3.963 3.963 0 00.422 9.419a3.963 3.963 0 000 3.559 3.963 3.963 0 003.541 2.184c1.07 0 1.97-.352 2.627-.957.748-.69 1.18-1.71 1.18-2.916a4.722 4.722 0 00-.07-.806H3.964v1.526h2.14a1.835 1.835 0 01-.79 1.205c-.356.241-.814.379-1.35.379-1.034 0-1.911-.697-2.225-1.636a2.375 2.375 0 010-1.517c.314-.94 1.191-1.636 2.225-1.636a2.152 2.152 0 011.52.594l1.132-1.13a3.808 3.808 0 00-2.652-1.033zm6.501.55v6.9h.886V11.89h1.465c.603 0 1.11-.196 1.522-.588a1.911 1.911 0 00.635-1.464 1.92 1.92 0 00-.635-1.456 2.125 2.125 0 00-1.522-.598zm2.427.85a1.156 1.156 0 01.823.365 1.176 1.176 0 010 1.686 1.171 1.171 0 01-.877.357H11.35V8.635h1.487a1.156 1.156 0 01.054 0zm4.124 1.175c-.842 0-1.477.308-1.907.925l.781.491c.288-.417.68-.626 1.175-.626a1.255 1.255 0 01.856.323 1.009 1.009 0 01.366.785v.202c-.34-.193-.774-.289-1.3-.289-.617 0-1.11.145-1.479.434-.37.288-.554.677-.554 1.165a1.476 1.476 0 00.525 1.156c.35.308.785.463 1.305.463.61 0 1.098-.27 1.465-.81h.038v.655h.848v-2.909c0-.61-.19-1.09-.568-1.44-.38-.35-.896-.525-1.551-.525zm2.263.154l1.946 4.422-1.098 2.38h.915L24 9.963h-.965l-1.368 3.391h-.02l-1.406-3.39zm-2.146 2.368c.494 0 .88.11 1.156.33 0 .372-.147.696-.44.973a1.413 1.413 0 01-.997.414 1.081 1.081 0 01-.69-.232.708.708 0 01-.293-.578c0-.257.12-.47.363-.647.24-.173.54-.26.9-.26Z', '#3C4043'],
    'dpd'       => ['1.5 0 21 24',    'M16.01 10.71a.364.364 0 01-.343-.006l-.558-.331a.43.43 0 01-.182-.312l-.014-.65a.363.363 0 01.165-.3l6.7-3.902L12.377.085A.799.799 0 0012 0a.798.798 0 00-.377.085l-9.4 5.124 10.53 6.13c.098.054.172.181.172.295v8.944c0 .112-.08.241-.178.294l-.567.315c-.171.062-.256.043-.361 0l-.569-.315a.362.362 0 01-.175-.294v-7.973a.223.223 0 00-.095-.156L1.702 7.048v10.579c0 .236.167.528.371.648l9.556 5.636c.102.06.237.09.371.089a.745.745 0 00.371-.09l9.557-5.635a.835.835 0 00.37-.648V7.047Z',       '#DC0032'],
];

/** Un logo de marque, en ligne. */
function znak_marka(string $slug, string $cls = 'znak-svg'): string {
    [$vb, $d, $fill] = WSM_ZNAK_PATHS[$slug];
    return '<svg class="' . $cls . '" viewBox="' . $vb . '" role="img" aria-hidden="true" focusable="false">'
         . '<path fill="' . $fill . '" d="' . $d . '"/></svg>';
}

/** Mastercard : deux cercles, l'orange où ils se recouvrent. */
function znak_mastercard(): string {
    return '<svg class="znak-svg znak-svg--mc" viewBox="0 0 24 15" aria-hidden="true" focusable="false">'
         . '<circle cx="8.5" cy="7.5" r="7" fill="#EB001B"/><circle cx="15.5" cy="7.5" r="7" fill="#F79E1B"/>'
         . '<path fill="#FF5F00" d="M12 1.437A7 7 0 0 1 12 13.563 7 7 0 0 1 12 1.437Z"/></svg>';
}

/** Le bâtiment de banque des deux virements ; « online » porte un éclair. */
function znak_bank(): string {
    return '<svg class="znak-svg znak-svg--ikona" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
         . '<path d="M3 9.5 12 4l9 5.5M4.5 10.5v7M9 10.5v7M15 10.5v7M19.5 10.5v7M3 19.5h18M3 10h18"/>'
         . '<path d="M13.2 1.5 11.4 5h2.4l-1.8 3.5" stroke-width="1.4"/>'
         . '</svg>';
}

/**
 * Le contenu d'une tuile. Les codes sont ceux de metody.php ; un code
 * inconnu rend une chaîne vide, et la tuile n'est pas imprimée.
 */
function znak_tile(string $code): string {
    switch ($code) {
        case 'inpost_paczkomat':
            return '<span class="znak-tile znak-tile--inpost"><b>InPost</b><i>Paczkomat 24/7</i></span>';
        case 'inpost_kurier':
            return '<span class="znak-tile znak-tile--inpost"><b>InPost</b><i>Kurier</i></span>';
        case 'dpd_kurier':
            return '<span class="znak-tile znak-tile--dpd">' . znak_marka('dpd', 'znak-svg znak-svg--dpd') . '<b>dpd</b></span>';
        case 'dpd_pickup':
            return '<span class="znak-tile znak-tile--dpd znak-tile--pickup">' . znak_marka('dpd', 'znak-svg znak-svg--dpd') . '<b>dpd</b><i>Pickup</i></span>';
        case 'blik':
            return '<span class="znak-tile znak-tile--blik"><b>blik</b></span>';
        case 'visa':
            return '<span class="znak-tile">' . znak_marka('visa', 'znak-svg znak-svg--visa') . '</span>';
        case 'mastercard':
            return '<span class="znak-tile">' . znak_mastercard() . '</span>';
        case 'applepay':
            return '<span class="znak-tile">' . znak_marka('applepay', 'znak-svg znak-svg--apple') . '</span>';
        case 'googlepay':
            return '<span class="znak-tile">' . znak_marka('googlepay', 'znak-svg znak-svg--gpay') . '</span>';
        case 'przelew_online':
            return '<span class="znak-tile znak-tile--bank">' . znak_bank() . '</span>';
    }
    return '';
}

/**
 * Une rangée de tuiles avec leur légende. $etiquette rend le libellé d'un
 * code (Treści : metoda.<code>, sinon le nom de la console).
 */
function znaki_lista(array $codes, callable $etiquette, string $cls = ''): string {
    $out = '';
    foreach ($codes as $c) {
        $t = znak_tile((string) $c);
        if ($t === '') continue;
        $out .= '<li class="znak znak--' . htmlspecialchars((string) $c, ENT_QUOTES) . '">' . $t
              . '<span class="znak-label">' . htmlspecialchars((string) $etiquette((string) $c), ENT_QUOTES) . '</span></li>';
    }
    return $out === '' ? '' : '<ul class="znaki' . ($cls !== '' ? ' ' . $cls : '') . '">' . $out . '</ul>';
}

/**
 * Les icônes des trois promesses, quand aucune n'est déposée dans
 * Integracje → Sklep. EN COULEUR, à la demande de l'équipe : le camion
 * caramel avec son horloge (24 h), le colis couleur carton, le thermomètre
 * et son flocon bleu. Le trait reste celui de la marque (currentColor) :
 * Wygląd change le contour avec le reste, les couleurs disent la chose.
 */
function ikona_obietnicy(int $i): string {
    $d = match ($i) {
        1 => '<rect x="3" y="13" width="24" height="17" rx="2" fill="#C68A3C"/>'
           . '<path d="M27 18h7.2a2 2 0 0 1 1.6.8l5.4 7.2a2 2 0 0 1 .4 1.2V30H27Z" fill="#F4E3C8"/>'
           . '<path d="M30 21h3.8l3.4 4.5H30Z" fill="#7CC0EA" stroke-width="1.4"/>'
           . '<path d="M3 30h38.6"/>'
           . '<circle cx="11" cy="33.5" r="3.6" fill="#3B2416" stroke="none"/><circle cx="11" cy="33.5" r="1.3" fill="#E8D9C4" stroke="none"/>'
           . '<circle cx="33" cy="33.5" r="3.6" fill="#3B2416" stroke="none"/><circle cx="33" cy="33.5" r="1.3" fill="#E8D9C4" stroke="none"/>'
           . '<circle cx="15" cy="21.5" r="5.8" fill="#FFFFFF"/><path d="M15 18.5v3l2.2 1.5" stroke-width="1.6"/>',
        2 => '<path d="M24 6 41 14.5 24 23 7 14.5Z" fill="#E2B97E"/>'
           . '<path d="M7 14.5 24 23v20L7 34.5Z" fill="#C99558"/>'
           . '<path d="M24 23 41 14.5v20L24 43Z" fill="#B07B40"/>'
           . '<path d="M13.6 11.3 17.6 9.3 34.4 17.7 30.4 19.7Z" fill="#F4E2BF" stroke="none"/>'
           . '<path d="M30.4 19.7 34.4 17.7v6.8l-4 2Z" fill="#E6C994" stroke="none"/>'
           . '<path d="M24 6 41 14.5v20L24 43 7 34.5v-20Z" fill="none"/><path d="M7 14.5 24 23l17-8.5M24 23v20" fill="none"/>'
           . '<path d="M10.5 29.5l5 2.5" stroke-width="1.4"/>',
        3 => '<path d="M14.5 29.4V10a4 4 0 0 1 8 0v19.4a7 7 0 1 1-8 0Z" fill="#FFFFFF"/>'
           . '<rect x="17" y="16" width="3" height="16" rx="1.5" fill="#E0473C" stroke="none"/>'
           . '<circle cx="18.5" cy="35.3" r="4.3" fill="#E0473C" stroke="none"/>'
           . '<path d="M23.5 13h2M23.5 17h2M23.5 21h2" stroke-width="1.3"/>'
           . '<g stroke="#2E8BD8" stroke-width="2.2"><path d="M36 4.5v17M28.6 8.75l14.8 8.5M28.6 17.25l14.8-8.5"/>'
           . '<path d="M33.6 6.2 36 8.4l2.4-2.2M33.6 19.8l2.4-2.2 2.4 2.2M29.3 11.9l3.1.9-.8 3.2M42.7 11.9l-3.1.9.8 3.2"/></g>',
        default => '',
    };
    if ($d === '') return '';
    return '<svg class="promise-rys" viewBox="0 0 48 48" width="48" height="48" fill="none" stroke="currentColor" '
         . 'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $d . '</svg>';
}

/**
 * Les icônes du bandeau en haut de la page d'accueil : le fourgon (darmowa
 * dostawa) et l'horloge (wysyłka tego samego dnia). Depuis l'habit « Apple »
 * le bandeau est clair : les dessins ont un contour brun pour s'y détacher.
 */
function ikona_korzysci(string $co): string {
    $d = match ($co) {
        'bus' => '<path d="M4 6h26l7.5 6.5h5A2.5 2.5 0 0 1 45 15v9H4Z" fill="#F6DDB8" stroke="#7A4E36" stroke-width="1.3" stroke-linejoin="round"/>'
               . '<path d="M31.4 8.6 36.4 13H31.4Z" fill="#7CC0EA"/>'
               . '<rect x="8" y="9" width="7" height="5" rx="1" fill="#7CC0EA"/><rect x="18" y="9" width="9" height="5" rx="1" fill="#7CC0EA"/>'
               . '<rect x="4" y="17" width="41" height="2.6" fill="#C68A3C"/>'
               . '<circle cx="13" cy="24.5" r="3.9" fill="#1E120B" stroke="#FFFFFF" stroke-width="1.6"/>'
               . '<circle cx="36" cy="24.5" r="3.9" fill="#1E120B" stroke="#FFFFFF" stroke-width="1.6"/>'
               . '<path d="M0 10h2.5M0 14h1.8" stroke="#C68A3C" stroke-width="1.6" stroke-linecap="round"/>',
        'zegar' => '<circle cx="24" cy="15" r="12" fill="#FFFFFF" stroke="#C68A3C" stroke-width="2.6"/>'
                 . '<path d="M24 5.5v2M24 22.5v2M14.5 15h2M31.5 15h2" stroke="#7A5A3E" stroke-width="1.6" stroke-linecap="round"/>'
                 . '<path d="M24 9.5V15l4 2.6" fill="none" stroke="#2B1A12" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>'
                 . '<circle cx="24" cy="15" r="1.4" fill="#2B1A12"/>',
        default => '',
    };
    if ($d === '') return '';
    return '<svg class="korzysc-ikona" viewBox="0 0 48 30" width="40" height="25" aria-hidden="true" focusable="false">' . $d . '</svg>';
}

/** Les pictogrammes du contact : telefon, e-mail, formularz. */
function ikona_kontaktu(string $co): string {
    $d = match ($co) {
        'telefon' => '<path d="M6.8 3.5h2.9l1.5 4.1-2.1 1.5a12.5 12.5 0 0 0 5.8 5.8l1.5-2.1 4.1 1.5v2.9a2 2 0 0 1-2.2 2A16.8 16.8 0 0 1 4.8 5.7a2 2 0 0 1 2-2.2Z"/>',
        'email'   => '<rect x="3" y="5.5" width="18" height="13" rx="2"/><path d="m3.5 7 8.5 6.2L20.5 7"/>',
        'formularz' => '<path d="M4 5.5h16v10H9.5L5.5 19v-3.5H4Z"/><path d="M8 9.5h8M8 12.5h5"/>',
        default   => '',
    };
    if ($d === '') return '';
    return '<svg class="kontakt-ikona" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" '
         . 'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $d . '</svg>';
}

/**
 * Le cadeau des paliers de remise — à côté de « Dodaj kolejne 10 kg, aby
 * uzyskać rabat 5 % », comme sur les maquettes de l'équipe (fiche produit et
 * panier). Un dessin, pas un émoji : l'émoji change de forme d'un téléphone à
 * l'autre, et sur certains il n'existe pas.
 */
function ikona_prezentu(): string {
    return '<svg class="prezent" viewBox="0 0 40 40" width="40" height="40" aria-hidden="true" focusable="false">'
         . '<path d="M20 12.5c-2.6-5.6-9.6-8.3-10.8-4.6-.9 2.8 4.3 4.6 10.8 4.6Z" fill="#E5482F"/>'
         . '<path d="M20 12.5c2.6-5.6 9.6-8.3 10.8-4.6.9 2.8-4.3 4.6-10.8 4.6Z" fill="#C7301D"/>'
         . '<rect x="6.5" y="18" width="27" height="18" rx="2" fill="#F5A623"/>'
         . '<rect x="20" y="18" width="13.5" height="18" rx="2" fill="#E8901A"/>'
         . '<rect x="4.5" y="12.5" width="31" height="7" rx="1.8" fill="#FBBF3F"/>'
         . '<rect x="17.5" y="12.5" width="5" height="23.5" fill="#E5482F"/>'
         . '<rect x="20" y="12.5" width="2.5" height="23.5" fill="#C7301D"/>'
         . '</svg>';
}

/**
 * Le colis du bandeau « Dostawa gratis, próg osiągnięty » : un carton vu de
 * trois quarts, le scotch, et trois traits verts — le seuil est atteint.
 * Sans les traits (en attente du seuil), il reste là, en sourdine.
 */
function ilustracja_paczki(bool $ok = true): string {
    return '<svg class="paczka" viewBox="0 0 120 72" width="120" height="72" aria-hidden="true" focusable="false">'
         . '<ellipse cx="72" cy="65" rx="34" ry="4" fill="rgba(80,46,20,.14)"/>'
         . '<path d="M40 22 78 11 106 23 68 35Z" fill="#E9B572"/>'
         . '<path d="M40 22 68 35V64L40 51Z" fill="#CB8B46"/>'
         . '<path d="M68 35 106 23V52L68 64Z" fill="#B7773A"/>'
         . '<path d="M56 17.4 62 15.6 90 28.2 84 30.2Z" fill="#F6E0B5"/>'
         . '<path d="M84 30.2 90 28.2V37.2L84 39.2Z" fill="#EBCB95"/>'
         . '<path d="M45 35 58 41.2V49L45 42.8Z" fill="#F8EBD5"/>'
         . '<path d="M47.5 39.5 55.5 43.3M47.5 42.5 53 45.1" stroke="#C9A77C" stroke-width="1.2" stroke-linecap="round"/>'
         . ($ok ? '<path d="M104 12l4-6M109.5 18.5l7-3M98 9.5V3" stroke="#4FAE4A" stroke-width="3" stroke-linecap="round"/>'
                : '')
         . '</svg>';
}

/**
 * LE TRANSPORTEUR EN IMAGE, sur chaque option de livraison du panier : la
 * consigne jaune d'InPost, le fourgon blanc d'InPost, le fourgon rouge de
 * DPD, la boutique Pickup de DPD. Ce qui décide est la LIGNE de la table
 * (transporteur + type de service), pas le nom de la méthode : une méthode
 * ajoutée demain dans Dostawa reçoit son dessin sans qu'on y touche. Un
 * transporteur inconnu reçoit un camion neutre, jamais le logo d'un autre.
 */
function ilustracja_dostawy(string $carrier, string $kind): string {
    $carrier = strtolower($carrier);
    $kola = '<circle cx="21" cy="49" r="6.6" fill="#1F1F1F"/><circle cx="21" cy="49" r="2.8" fill="#C9C9C9"/>'
          . '<circle cx="74" cy="49" r="6.6" fill="#1F1F1F"/><circle cx="74" cy="49" r="2.8" fill="#C9C9C9"/>';
    $cien = '<ellipse cx="48" cy="56.5" rx="42" ry="3" fill="rgba(0,0,0,.13)"/>';
    $van = function (string $kolor, string $obrys, string $logo) use ($kola, $cien): string {
        return $cien
             . '<path d="M6 12a4 4 0 0 1 4-4h53v39H6Z" fill="' . $kolor . '" stroke="' . $obrys . '" stroke-width=".8"/>'
             . '<path d="M63 16h13.6a5 5 0 0 1 4.3 2.4L88.4 31a4 4 0 0 1 .6 2.1V47H63Z" fill="' . $kolor . '" stroke="' . $obrys . '" stroke-width=".8"/>'
             . '<path d="M67 20h9a2.5 2.5 0 0 1 2.1 1.2L83.4 30H67Z" fill="#2E3D4F"/>'
             . '<path d="M65 19v26" stroke="' . $obrys . '" stroke-width=".8"/>'
             . '<rect x="85.5" y="35" width="3" height="3.4" rx=".8" fill="#FFE39A"/>'
             . $logo
             . '<rect x="3.5" y="45.5" width="87" height="4" rx="2" fill="#3A3A3A"/>'
             . $kola;
    };
    if ($carrier === 'inpost' && $kind === 'punkt') {
        $d = $cien
           . '<rect x="8" y="5" width="80" height="50" rx="3" fill="#FFCD00" stroke="#D4A300"/>'
           . '<path d="M8 8a3 3 0 0 1 3-3h74a3 3 0 0 1 3 3v6H8Z" fill="#3C3C3B"/>'
           . '<text x="48" y="12.3" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="6.2" font-weight="700" fill="#FFCD00">InPost</text>';
        foreach ([11, 24.5, 61, 74.5] as $x) {
            foreach ([17, 29.5, 42] as $y) {
                $d .= '<rect x="' . $x . '" y="' . $y . '" width="11" height="10.5" rx="1" fill="#FFD84A" stroke="#D4A300" stroke-width=".7"/>'
                    . '<rect x="' . ($x + 8.2) . '" y="' . ($y + 4.4) . '" width="1.3" height="2.6" rx=".5" fill="#A88300"/>';
            }
        }
        $d .= '<rect x="38" y="17" width="20" height="35.5" rx="1.2" fill="#F2BC00" stroke="#D4A300" stroke-width=".7"/>'
            . '<rect x="41" y="20" width="14" height="9" rx="1" fill="#2E3D4F"/>'
            . '<path d="M42.5 33h3M47 33h3M51.5 33h3M42.5 36.4h3M47 36.4h3M51.5 36.4h3M42.5 39.8h3M47 39.8h3M51.5 39.8h3" stroke="#6B5A1A" stroke-width="1.6"/>'
            . '<rect x="42" y="44" width="12" height="4" rx="1" fill="#3C3C3B"/>';
    } elseif ($carrier === 'inpost') {
        $d = $van('#FFFFFF', '#CFCFCF',
              '<rect x="6.4" y="36" width="56.2" height="5" fill="#FFCD00"/>'
            . '<circle cx="15.5" cy="24" r="4.6" fill="#FFCD00"/>'
            . '<path d="M15.5 16.8v2.4M15.5 28.8v2.4M8.3 24h2.4M20.3 24h2.4M10.4 18.9l1.7 1.7M18.9 27.4l1.7 1.7M20.6 18.9l-1.7 1.7M12.1 27.4l-1.7 1.7" stroke="#FFCD00" stroke-width="1.5" stroke-linecap="round"/>'
            . '<text x="42.5" y="27" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="8.6" font-weight="700" fill="#3C3C3B">InPost</text>');
    } elseif ($carrier === 'dpd' && $kind === 'punkt') {
        $d = $cien
           . '<rect x="14" y="22" width="68" height="33" rx="1.5" fill="#F4EFE9" stroke="#CFC6BB"/>'
           . '<path d="M10 16h76l-3 9H13Z" fill="#DC0032"/>'
           . '<path d="M22 16l-2 9M34 16l-1 9M46 16v9M58 16l1 9M70 16l2 9" stroke="#FFFFFF" stroke-width="3.2"/>'
           . '<rect x="27" y="5" width="42" height="10" rx="2" fill="#DC0032"/>'
           . '<text x="48" y="12.5" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="6.5" font-weight="700" fill="#FFFFFF">dpd Pickup</text>'
           . '<rect x="20" y="31" width="16" height="13" rx="1" fill="#BFD6E6"/><rect x="60" y="31" width="16" height="13" rx="1" fill="#BFD6E6"/>'
           . '<rect x="42" y="33" width="12" height="22" rx="1" fill="#8C6E52"/><circle cx="51.5" cy="44" r=".9" fill="#F4EFE9"/>';
    } elseif ($carrier === 'dpd') {
        [, $kostka] = WSM_ZNAK_PATHS['dpd'];
        $d = $van('#DC0032', '#A8001F',
              '<rect x="6.4" y="38" width="56.2" height="7" fill="#B8002A"/>'
            . '<g transform="translate(15 16) scale(.62)"><path fill="#FFFFFF" d="' . $kostka . '"/></g>'
            . '<text x="42" y="28" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="12" font-weight="700" fill="#FFFFFF">dpd</text>');
    } else {
        $d = $van('#8A5A36', '#6E4528',
              '<rect x="6.4" y="38" width="56.2" height="7" fill="#6E4528"/>'
            . '<rect x="16" y="17" width="34" height="14" rx="2" fill="#C68A3C"/>');
    }
    return '<svg class="dostawa-rys" viewBox="0 0 96 60" width="96" height="60" aria-hidden="true" focusable="false">' . $d . '</svg>';
}

/** Les petits pictogrammes du panier : corbeille, moins, plus, flèches. */
function ikona_ui(string $co): string {
    $d = match ($co) {
        'kosz'   => '<path d="M4.5 7h15M9.5 7V4.8a.8.8 0 0 1 .8-.8h3.4a.8.8 0 0 1 .8.8V7M6.5 7l.9 12.2A1.9 1.9 0 0 0 9.3 21h5.4a1.9 1.9 0 0 0 1.9-1.8L17.5 7M10.2 10.5v7M13.8 10.5v7"/>',
        'minus'  => '<path d="M6 12h12"/>',
        'plus'   => '<path d="M6 12h12M12 6v12"/>',
        'dalej'  => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'wstecz' => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
        'pauza'  => '<path d="M9 6v12M15 6v12"/>',
        'graj'   => '<path d="M8 5.5v13l10.5-6.5Z"/>',
        'ok'     => '<path d="M6.5 12.5l3.6 3.6 7.4-8"/>',
        'klodka' => '<path d="M7 10.5V8a5 5 0 0 1 10 0v2.5M5.8 10.5h12.4a.8.8 0 0 1 .8.8v8.4a.8.8 0 0 1-.8.8H5.8a.8.8 0 0 1-.8-.8v-8.4a.8.8 0 0 1 .8-.8ZM12 14.5v2.5"/>',
        default  => '',
    };
    if ($d === '') return '';
    return '<svg class="ui-ikona" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" '
         . 'stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $d . '</svg>';
}
