<?php
// ============================================================================
//  instagram.php — Instagram et Facebook CÔTÉ VISITEUR (26.11.2026).
//
//  · Les boutons « Obserwuj » du pied de page : les adresses des profils
//    (Marketing → Meta : meta.instagram_url, meta.facebook_url). Un lien
//    simple — aucun script de Meta, aucun cookie, rien à consentir.
//  · Le bandeau « Instagram » de l'accueil : les derniers posts du compte
//    déjà relié pour publier (meta.ig_user_id + meta.page_token), lus PAR LE
//    SERVEUR et gardés une heure. Le navigateur du client ne parle jamais à
//    Meta : les photos viennent du CDN d'Instagram, sans traceur.
//
//  Un compte non relié, un jeton expiré, Meta muet : le bandeau ne s'affiche
//  pas (jamais de cadre vide), la dernière lecture réussie sert en attendant.
// ============================================================================
declare(strict_types=1);

require_once __DIR__ . '/reklama.php';

const WSM_IG_CACHE_CLE = 'ig_feed_cache';
const WSM_IG_CACHE_TTL = 3600;       // une heure : les adresses des photos d'Instagram expirent au bout de quelques jours
const WSM_IG_LIMITE    = 12;

/** Les adresses des profils, vérifiées : https et le bon réseau, sinon ''. */
function wsm_social_profils(): array {
    $c = wsm_config()['meta'] ?? [];
    $u = fn(string $k, string $re) => preg_match($re, trim((string) ($c[$k] ?? ''))) ? trim((string) $c[$k]) : '';
    $ig = $u('instagram_url', '#^https://(www\.)?instagram\.com/[A-Za-z0-9._]{1,40}/?$#');
    $fb = $u('facebook_url', '#^https://(www\.|m\.)?facebook\.com/[^\s"<>]{1,120}$#');
    if ($ig === '') {
        // Le compte relié pour publier dit lui-même son nom : on s'en sert si l'adresse n'a pas été saisie.
        $cache = wsm_ig_cache_lire();
        $user = (string) ($cache['user'] ?? '');
        if (preg_match('/^[A-Za-z0-9._]{1,40}$/', $user)) $ig = 'https://www.instagram.com/' . $user . '/';
    }
    return ['instagram' => $ig, 'facebook' => $fb];
}

function wsm_ig_cache_lire(): array {
    try {
        $st = wsm_pdo()->prepare("SELECT val FROM wsm_settings WHERE cle = ?");
        $st->execute([WSM_IG_CACHE_CLE]);
        $v = json_decode((string) ($st->fetchColumn() ?: ''), true);
        return is_array($v) ? $v : [];
    } catch (Throwable $e) { return []; }
}
function wsm_ig_cache_ecrire(array $v): void {
    try {
        $pdo = wsm_pdo(); $now = date('Y-m-d H:i:s'); $val = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $up = $pdo->prepare("UPDATE wsm_settings SET val = ?, secret = 0, updated_at = ?, updated_by = 'instagram' WHERE cle = ?");
        $up->execute([$val, $now, WSM_IG_CACHE_CLE]);
        if ($up->rowCount() === 0) {
            try { $pdo->prepare("INSERT INTO wsm_settings (cle, val, secret, updated_at, updated_by) VALUES (?,?,0,?,'instagram')")->execute([WSM_IG_CACHE_CLE, $val, $now]); }
            catch (Throwable $e) { /* déjà là, valeur identique */ }
        }
    } catch (Throwable $e) { error_log('instagram cache: ' . $e->getMessage()); }
}

/**
 * Les derniers posts : [['src','link','alt','video'], …] — la lecture gardée si elle a
 * moins d'une heure, sinon une nouvelle (6 s au plus ; un échec garde l'ancienne).
 */
function wsm_ig_feed(int $n = 6, bool $forcer = false): array {
    $cfg = wsm_ads_cfg();
    if ($cfg['ig'] === '' || $cfg['page_token'] === '') return [];
    $cache = wsm_ig_cache_lire();
    $frais = !$forcer && isset($cache['at']) && time() - (int) $cache['at'] < WSM_IG_CACHE_TTL && ($cache['ig'] ?? '') === $cfg['ig'];
    if (!$frais) {
        // L'heure d'abord : deux visiteurs en même temps ne relisent pas Meta deux fois.
        wsm_ig_cache_ecrire(array_merge($cache, ['at' => time()]));
        $r = wsm_graph_call('GET', $cfg['ig'] . '/media', ['fields' => 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp', 'limit' => WSM_IG_LIMITE],
                            $cfg['page_token'], [], $cfg['graph'], 6);
        if ($r['ok']) {
            $items = [];
            foreach ((array) ($r['data']['data'] ?? []) as $m) {
                $video = ($m['media_type'] ?? '') === 'VIDEO';
                $src = (string) ($video ? ($m['thumbnail_url'] ?? '') : ($m['media_url'] ?? ''));
                $link = (string) ($m['permalink'] ?? '');
                if (!preg_match('#^https://#', $src) || !preg_match('#^https://(www\.)?instagram\.com/#', $link)) continue;
                $alt = trim(preg_replace('/\s+/u', ' ', preg_replace('/#\S+/u', '', (string) ($m['caption'] ?? ''))) ?? '');
                $items[] = ['src' => $src, 'link' => $link, 'alt' => mb_substr($alt, 0, 140), 'video' => $video];
            }
            $user = (string) ($cache['user'] ?? '');
            if ($user === '') { $u = wsm_graph_call('GET', $cfg['ig'], ['fields' => 'username'], $cfg['page_token'], [], $cfg['graph'], 6); if ($u['ok']) $user = (string) ($u['data']['username'] ?? ''); }
            $cache = ['at' => time(), 'ig' => $cfg['ig'], 'items' => $items, 'user' => $user, 'erreur' => ''];
        } else {
            $cache = array_merge($cache, ['at' => time(), 'erreur' => mb_substr((string) $r['error'], 0, 200)]);
        }
        wsm_ig_cache_ecrire($cache);
    }
    return array_slice((array) ($cache['items'] ?? []), 0, $n);
}

/** L'état pour la console : relié ? combien de posts ? dernière lecture, erreur. */
function wsm_ig_etat(): array {
    $cfg = wsm_ads_cfg(); $c = wsm_ig_cache_lire();
    return ['relie' => $cfg['ig'] !== '' && $cfg['page_token'] !== '', 'posts' => count((array) ($c['items'] ?? [])),
            'lu' => isset($c['at']) ? date('Y-m-d H:i', (int) $c['at']) : '', 'erreur' => (string) ($c['erreur'] ?? ''), 'user' => (string) ($c['user'] ?? '')];
}

/** Les icônes, au trait, dans la couleur du texte (currentColor). */
function wsm_social_ikona(string $k): string {
    return $k === 'instagram'
        ? '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4.2"/><circle cx="17.4" cy="6.6" r="1" fill="currentColor" stroke="none"/></svg>'
        : '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M14 8h3V4h-3a4 4 0 0 0-4 4v3H7v4h3v6h4v-6h3l1-4h-4V8.5a.5.5 0 0 1 .5-.5z"/></svg>';
}
