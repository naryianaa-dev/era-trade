<?php
/**
 * favorites_widget.php
 *
 * Подключаемый блок: общие стили и JS для звёздочки «Избранное».
 *
 * Подключается ДО рендера страницы — определяет helper-функции:
 *   - favorites_lookup(PDO, user_id, lot_type, ids)  → array<int,bool>
 *   - favorites_render_star(lot_type, lot_id, is_fav, lang) → HTML
 *   - favorites_render_assets($lang)  → выводит <style>+<script> (вызывать
 *     один раз после <body>, до отрисовки звёздочек).
 *
 * Включает таблицу user_favorites (через db_schema_extra.php).
 */

if (!function_exists('favorites_lookup')) {
    function favorites_lookup(PDO $pdo, int $user_id, string $lot_type, array $ids): array {
        if ($user_id <= 0 || empty($ids) || !in_array($lot_type, ['lot','torgi'], true)) {
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_filter($ids, fn($v) => $v > 0);
        if (empty($ids)) return [];
        $place = implode(',', array_fill(0, count($ids), '?'));
        try {
            $sql = "SELECT lot_id FROM user_favorites
                    WHERE user_id = ? AND lot_type = ? AND lot_id IN ($place)";
            $st = $pdo->prepare($sql);
            $st->execute(array_merge([$user_id, $lot_type], $ids));
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $out[(int)$id] = true;
            }
            return $out;
        } catch (Throwable $e) {
            error_log('favorites_lookup: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('favorites_render_star')) {
    function favorites_render_star(string $lot_type, int $lot_id, bool $is_fav, string $lang, string $extra_class = ''): string {
        $title = $is_fav
            ? ($lang === 'en' ? 'Remove from favorites' : 'Убрать из избранного')
            : ($lang === 'en' ? 'Add to favorites' : 'Добавить в избранное');
        $cls = 'fav-star';
        if ($extra_class !== '') $cls .= ' ' . $extra_class;
        if ($is_fav) $cls .= ' is-fav';
        $aria = $is_fav ? 'true' : 'false';
        return '<button type="button" class="' . $cls . '" '
             . 'data-lot-type="' . htmlspecialchars($lot_type) . '" '
             . 'data-lot-id="' . (int)$lot_id . '" '
             . 'aria-pressed="' . $aria . '" '
             . 'title="' . htmlspecialchars($title) . '" '
             . 'onclick="toggleFavorite(event,this)">'
             . '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">'
             . '<path d="M12 2.6l3.09 6.26 6.91 1-5 4.87 1.18 6.87L12 18.4l-6.18 3.2L7 14.73l-5-4.87 6.91-1L12 2.6z" '
             . 'stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" fill="var(--fav-fill, none)"/>'
             . '</svg>'
             . '</button>';
    }
}

if (!function_exists('favorites_render_assets')) {
    function favorites_render_assets(string $lang = 'ru'): void {
        static $rendered = false;
        if ($rendered) return;
        $rendered = true;
        $lang_json = json_encode($lang, JSON_UNESCAPED_UNICODE);
        ?>
<style>
.fav-star{position:absolute;top:10px;right:10px;width:38px;height:38px;display:inline-flex;align-items:center;justify-content:center;background:rgba(15,23,42,.55);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);border:1px solid rgba(255,255,255,.18);border-radius:50%;color:#e2e8f0;cursor:pointer;z-index:5;transition:transform .15s ease,background .2s ease,color .2s ease;-webkit-tap-highlight-color:transparent;padding:0}
.fav-star:hover{transform:scale(1.08);background:rgba(15,23,42,.75);color:#fbbf24}
.fav-star.is-fav{color:#fbbf24;--fav-fill:#fbbf24}
.fav-star.is-fav:hover{color:#f59e0b;--fav-fill:#f59e0b}
.fav-star.busy{opacity:.6;pointer-events:none}
.fav-star-inline{position:static;display:inline-flex;width:30px;height:30px;background:transparent;border:none;color:#94a3b8;backdrop-filter:none;-webkit-backdrop-filter:none}
.fav-star-inline:hover{color:#fbbf24;background:transparent;transform:none}
.fav-star-inline.is-fav{color:#fbbf24;--fav-fill:#fbbf24}
.fav-toast{position:fixed;left:50%;top:24px;transform:translateX(-50%) translateY(-20px);background:#0f172a;color:#fff;padding:10px 18px;border-radius:10px;font-size:14px;font-weight:600;box-shadow:0 8px 24px rgba(0,0,0,.25);z-index:9999;opacity:0;transition:opacity .25s ease,transform .25s ease;pointer-events:none}
.fav-toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
</style>
<script>
(function(){
    if (window.toggleFavorite) return;
    var T = {
        ru: { added: 'Добавлено в избранное', removed: 'Убрано из избранного',
              login: 'Войдите, чтобы добавлять в избранное', error: 'Не удалось обновить' },
        en: { added: 'Added to favorites', removed: 'Removed from favorites',
              login: 'Sign in to use favorites', error: 'Could not update' }
    };
    var LANG = <?= $lang_json ?>;
    var t = T[LANG] || T.ru;
    function toast(msg) {
        var el = document.createElement('div');
        el.className = 'fav-toast'; el.textContent = msg;
        document.body.appendChild(el);
        requestAnimationFrame(function(){ el.classList.add('show'); });
        setTimeout(function(){
            el.classList.remove('show');
            setTimeout(function(){ el.remove(); }, 300);
        }, 1800);
    }
    window.toggleFavorite = function(ev, btn) {
        if (ev) { ev.preventDefault(); ev.stopPropagation(); }
        if (!btn || btn.classList.contains('busy')) return false;
        var lotType = btn.getAttribute('data-lot-type');
        var lotId   = btn.getAttribute('data-lot-id');
        btn.classList.add('busy');
        var fd = new FormData();
        fd.append('lot_type', lotType);
        fd.append('lot_id', lotId);
        fetch('toggle_favorite.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r){ return r.json().then(function(j){ return { status: r.status, body: j }; }); })
            .then(function(res){
                btn.classList.remove('busy');
                var status = res.status, body = res.body;
                if (status === 401 || (body && body.error === 'auth_required')) {
                    toast(t.login);
                    if (typeof openAuth === 'function') openAuth('login');
                    return;
                }
                if (!body || !body.ok) { toast(t.error); return; }
                if (body.in_favorites) {
                    btn.classList.add('is-fav');
                    btn.setAttribute('aria-pressed', 'true');
                    toast(t.added);
                } else {
                    btn.classList.remove('is-fav');
                    btn.setAttribute('aria-pressed', 'false');
                    toast(t.removed);
                }
            })
            .catch(function(err){
                btn.classList.remove('busy');
                console.warn('favorite toggle failed', err);
                toast(t.error);
            });
        return false;
    };
})();
</script>
<?php
    }
}
