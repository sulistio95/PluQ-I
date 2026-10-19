<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once PLUQI_ROOT . '/config/database.php';
require_once PLUQI_ROOT . '/app/services/admin_auth.php';
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store');
header('Content-Type: text/html; charset=utf-8');
admin_session_start();
$pdo = database();
$user = admin_current_user($pdo);
if (!$user) { header('Location: /admin/'); exit; }
$canManage = admin_can_manage_settings($user);
$error = '';
$notice = '';

function gf_options_parse(string $raw): array {
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', trim($raw)) ?: [] as $line) {
        $line = trim($line); if ($line === '') continue;
        $parts = explode('|', $line, 2);
        $value = trim($parts[0]); $label = trim($parts[1] ?? $parts[0]);
        if ($value === '' || $label === '' || strlen($value) > 190 || strlen($label) > 190) throw new InvalidArgumentException('Opsi harus berformat nilai|Label dan maksimal 190 karakter.');
        if (isset($out[$value])) throw new InvalidArgumentException('Nilai opsi duplikat: ' . $value);
        $out[$value] = $label;
        if (count($out) > 100) throw new InvalidArgumentException('Maksimal 100 opsi per dropdown.');
    }
    return $out;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_csrf_valid($_POST['csrf'] ?? null)) { http_response_code(403); $error = 'Sesi formulir tidak valid. Muat ulang halaman.'; }
    elseif (!$canManage) { http_response_code(403); $error = 'Hanya ADMIN/SUPER_ADMIN yang dapat mengubah konfigurasi.'; }
    else {
        try {
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'save') {
                $id = filter_var($_POST['id'] ?? '0', FILTER_VALIDATE_INT);
                $gameId = filter_var($_POST['game_id'] ?? null, FILTER_VALIDATE_INT);
                $sort = filter_var($_POST['sort_order'] ?? '0', FILTER_VALIDATE_INT);
                if ($id === false || $id < 0 || !$gameId || $gameId < 1 || $sort === false || $sort < -100000 || $sort > 100000) throw new InvalidArgumentException('ID game, kolom, atau urutan tidak valid.');
                $id = (int)$id; $gameId = (int)$gameId;
                $key = trim((string)($_POST['field_key'] ?? ''));
                $label = trim((string)($_POST['label'] ?? ''));
                $type = (string)($_POST['field_type'] ?? 'text');
                $placeholder = trim((string)($_POST['placeholder'] ?? ''));
                $regex = trim((string)($_POST['validation_regex'] ?? ''));
                $required = isset($_POST['is_required']) ? 1 : 0;
                $status = (string)($_POST['status'] ?? 'ACTIVE');
                if (!preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $key)) throw new InvalidArgumentException('Kunci kolom: huruf kecil di awal, lalu huruf kecil/angka/garis bawah.');
                if ($label === '' || strlen($label) > 120 || strlen($placeholder) > 190 || strlen($regex) > 500) throw new InvalidArgumentException('Label wajib diisi; batas label 120, placeholder 190, regex 500 karakter.');
                if (!in_array($type, ['text','number','select'], true) || !in_array($status, ['ACTIVE','INACTIVE'], true)) throw new InvalidArgumentException('Tipe atau status kolom tidak valid.');
                if ($regex !== '' && @preg_match('~' . str_replace('~', '\\~', $regex) . '~u', '') === false) throw new InvalidArgumentException('Pola regex tidak valid.');
                $options = gf_options_parse((string)($_POST['options'] ?? ''));
                if ($type === 'select' && $status === 'ACTIVE' && !$options) throw new InvalidArgumentException('Dropdown aktif wajib memiliki minimal satu opsi.');
                $checkGame = $pdo->prepare('SELECT id FROM games WHERE id=:id'); $checkGame->execute(['id'=>$gameId]);
                if (!$checkGame->fetchColumn()) throw new InvalidArgumentException('Game tidak ditemukan.');
                $pdo->beginTransaction();
                try {
                    if ($id > 0) {
                        $check = $pdo->prepare('SELECT id FROM game_fields WHERE id=:id FOR UPDATE'); $check->execute(['id'=>$id]);
                        if (!$check->fetchColumn()) throw new InvalidArgumentException('Kolom akun tidak ditemukan.');
                        $stmt = $pdo->prepare('UPDATE game_fields SET game_id=:game,field_key=:key,label=:label,field_type=:type,placeholder=:placeholder,is_required=:required,validation_regex=:regex,sort_order=:sort,status=:status WHERE id=:id');
                        $stmt->execute(['game'=>$gameId,'key'=>$key,'label'=>$label,'type'=>$type,'placeholder'=>$placeholder ?: null,'required'=>$required,'regex'=>$regex ?: null,'sort'=>$sort,'status'=>$status,'id'=>$id]);
                        $auditAction = 'GAME_FIELD_UPDATED';
                    } else {
                        $stmt = $pdo->prepare('INSERT INTO game_fields (game_id,field_key,label,field_type,placeholder,is_required,validation_regex,sort_order,status) VALUES (:game,:key,:label,:type,:placeholder,:required,:regex,:sort,:status)');
                        $stmt->execute(['game'=>$gameId,'key'=>$key,'label'=>$label,'type'=>$type,'placeholder'=>$placeholder ?: null,'required'=>$required,'regex'=>$regex ?: null,'sort'=>$sort,'status'=>$status]);
                        $id = (int)$pdo->lastInsertId(); $auditAction = 'GAME_FIELD_CREATED';
                    }
                    if ($type === 'select') {
                        $pdo->prepare("UPDATE game_field_options SET status='INACTIVE' WHERE game_field_id=:id")->execute(['id'=>$id]);
                        $upsert = $pdo->prepare("INSERT INTO game_field_options (game_field_id,option_value,option_label,sort_order,status) VALUES (:id,:value,:label,:sort,'ACTIVE') ON DUPLICATE KEY UPDATE option_label=VALUES(option_label),sort_order=VALUES(sort_order),status='ACTIVE'");
                        $n=0; foreach ($options as $value=>$optionLabel) $upsert->execute(['id'=>$id,'value'=>$value,'label'=>$optionLabel,'sort'=>$n++]);
                    }
                    $audit = $pdo->prepare("INSERT INTO audit_logs (actor_type,actor_id,action,entity_type,entity_id,details,ip_address) VALUES ('ADMIN',:actor,:action,'GAME_FIELD',:entity,:details,:ip)");
                    $audit->execute(['actor'=>$user['id'],'action'=>$auditAction,'entity'=>(string)$id,'details'=>json_encode(['game_id'=>$gameId,'field_key'=>$key,'field_type'=>$type,'status'=>$status,'option_count'=>count($options)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'ip'=>substr((string)($_SERVER['REMOTE_ADDR']??''),0,45)]);
                    $pdo->commit();
                } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
                header('Location: /admin/game-fields.php?game_id='.$gameId.'&saved=1'); exit;
            } elseif ($action === 'toggle') {
                $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
                if (!$id || $id < 1) throw new InvalidArgumentException('ID kolom tidak valid.');
                $stmt = $pdo->prepare('SELECT id,game_id,field_key,field_type,status FROM game_fields WHERE id=:id'); $stmt->execute(['id'=>$id]); $f=$stmt->fetch(PDO::FETCH_ASSOC);
                if (!$f) throw new InvalidArgumentException('Kolom akun tidak ditemukan.');
                $new = $f['status'] === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE';
                if ($new === 'ACTIVE' && $f['field_type'] === 'select') {
                    $opts=$pdo->prepare("SELECT COUNT(*) FROM game_field_options WHERE game_field_id=:id AND status='ACTIVE'"); $opts->execute(['id'=>$id]);
                    if ((int)$opts->fetchColumn() < 1) throw new InvalidArgumentException('Dropdown belum memiliki opsi aktif; ubah dan simpan opsi terlebih dahulu.');
                }
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('UPDATE game_fields SET status=:status WHERE id=:id')->execute(['status'=>$new,'id'=>$id]);
                    $audit=$pdo->prepare("INSERT INTO audit_logs (actor_type,actor_id,action,entity_type,entity_id,details,ip_address) VALUES ('ADMIN',:actor,'GAME_FIELD_STATUS_CHANGED','GAME_FIELD',:entity,:details,:ip)");
                    $audit->execute(['actor'=>$user['id'],'entity'=>(string)$id,'details'=>json_encode(['game_id'=>(int)$f['game_id'],'field_key'=>$f['field_key'],'status'=>$new]),'ip'=>substr((string)($_SERVER['REMOTE_ADDR']??''),0,45)]);
                    $pdo->commit();
                } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
                header('Location: /admin/game-fields.php?game_id='.(int)$f['game_id'].'&saved=1'); exit;
            } else throw new InvalidArgumentException('Aksi tidak dikenal.');
        } catch (InvalidArgumentException $e) { $error=$e->getMessage(); }
        catch (PDOException $e) { $error='Gagal menyimpan. Pastikan migration 002 diterapkan dan kunci kolom tidak duplikat pada game yang sama.'; }
        catch (Throwable $e) { $error='Gagal menyimpan konfigurasi. Perubahan dibatalkan; periksa log server.'; }
    }
}
if (($_GET['saved'] ?? '') === '1') $notice='Konfigurasi form akun berhasil disimpan.';
$games=$pdo->query('SELECT id,name,status FROM games ORDER BY name,id')->fetchAll(PDO::FETCH_ASSOC);
$gameId=filter_var($_GET['game_id'] ?? null,FILTER_VALIDATE_INT);
$validIds=array_map(static fn($g)=>(int)$g['id'],$games);
$gameId=$gameId && in_array((int)$gameId,$validIds,true)?(int)$gameId:(int)($games[0]['id']??0);
$editId=filter_var($_GET['edit']??null,FILTER_VALIDATE_INT);
$edit=null; $fields=[];
if($gameId){
    $q=$pdo->prepare('SELECT * FROM game_fields WHERE game_id=:game ORDER BY sort_order,id');$q->execute(['game'=>$gameId]);$fields=$q->fetchAll(PDO::FETCH_ASSOC);
    if($editId && $editId>0){$q=$pdo->prepare('SELECT * FROM game_fields WHERE id=:id AND game_id=:game');$q->execute(['id'=>$editId,'game'=>$gameId]);$edit=$q->fetch(PDO::FETCH_ASSOC)?:null;}
}
$optionsText='';
if($edit && $edit['field_type']==='select'){$q=$pdo->prepare('SELECT option_value,option_label FROM game_field_options WHERE game_field_id=:id ORDER BY sort_order,id');$q->execute(['id'=>$edit['id']]);$optionsText=implode("\n",array_map(static fn($o)=>$o['option_value'].'|'.$o['option_label'],$q->fetchAll(PDO::FETCH_ASSOC)));}
$csrf=admin_csrf_token();
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Konfigurasi Form Akun · PluQ-I</title>
<style>
:root{color-scheme:dark;--bg:#0c0d10;--panel:#15171d;--line:#292d36;--text:#f3f4f6;--muted:#9ca3af;--accent:#a7f3d0}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,sans-serif}main{max-width:1180px;margin:auto;padding:24px}h1,h2{margin:0 0 12px}.muted{color:var(--muted)}.panel{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:18px;margin:16px 0}.bar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px}label{display:grid;gap:6px;color:var(--muted)}input,select,textarea,button{width:100%;border:1px solid var(--line);background:#0d0f13;color:var(--text);padding:10px;border-radius:7px;font:inherit}textarea{min-height:120px}button{cursor:pointer;background:#d1fae5;color:#111827;font-weight:700}button.secondary{background:#30343d;color:var(--text)}a{color:var(--accent)}.notice,.error{padding:10px;border-radius:7px;margin:12px 0}.notice{background:#15352b}.error{background:#3b1d22}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:10px;border-bottom:1px solid var(--line);vertical-align:top}th{color:var(--muted)}.small{font-size:12px}.actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.actions form{margin:0}.actions button{width:auto}.check{display:flex;align-items:center;gap:8px}.check input{width:auto}@media(max-width:650px){main{padding:12px}.panel{padding:12px}}
</style></head><body><main>
<header class="bar"><div><h1>Konfigurasi Form Akun</h1><div class="muted">Atur kolom yang tampil saat pelanggan memilih game.</div></div><a href="/admin/">Kembali ke panel admin</a></header>
<?php if($error!==''):?><div class="error"><?=admin_escape($error)?></div><?php endif;?><?php if($notice!==''):?><div class="notice"><?=admin_escape($notice)?></div><?php endif;?>
<?php if(!$canManage):?><div class="error">Akun hanya dapat melihat. Perubahan memerlukan peran ADMIN/SUPER_ADMIN.</div><?php endif;?>
<section class="panel"><h2>Pilih game</h2><form method="get" class="grid"><label>Game<select name="game_id"><?php foreach($games as $g):?><option value="<?=$g['id']?>" <?=$gameId===(int)$g['id']?'selected':''?>><?=admin_escape($g['name'])?> · <?=admin_escape($g['status'])?></option><?php endforeach;?></select></label><div><label>&nbsp;</label><button type="submit">Tampilkan kolom</button></div></form><p class="small muted">Kunci kolom menjadi nama data akun dalam aplikasi. Contoh: <code>user_id</code>, <code>zone_id</code>, <code>region</code>. Pastikan sesuai kebutuhan game dan integrasi supplier.</p></section>
<section class="panel"><h2><?=$edit?'Ubah kolom':'Tambah kolom'?></h2><form method="post"><input type="hidden" name="csrf" value="<?=admin_escape($csrf)?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=admin_escape($edit['id']??0)?>"><input type="hidden" name="game_id" value="<?=$gameId?>">
<div class="grid"><label>Kunci kolom<input name="field_key" maxlength="80" pattern="[a-z][a-z0-9_]{0,79}" required value="<?=admin_escape($edit['field_key']??'user_id')?>"></label><label>Label<input name="label" maxlength="120" required value="<?=admin_escape($edit['label']??'User ID')?>"></label><label>Tipe<select name="field_type"><option value="text" <?=($edit['field_type']??'text')==='text'?'selected':''?>>Teks</option><option value="number" <?=($edit['field_type']??'')==='number'?'selected':''?>>Angka</option><option value="select" <?=($edit['field_type']??'')==='select'?'selected':''?>>Dropdown</option></select></label><label>Placeholder<input name="placeholder" maxlength="190" value="<?=admin_escape($edit['placeholder']??'')?>" placeholder="Masukkan User ID"></label><label>Urutan<input type="number" name="sort_order" min="-100000" max="100000" value="<?=admin_escape($edit['sort_order']??0)?>"></label><label>Status<select name="status"><option value="ACTIVE" <?=($edit['status']??'ACTIVE')==='ACTIVE'?'selected':''?>>Aktif</option><option value="INACTIVE" <?=($edit['status']??'')==='INACTIVE'?'selected':''?>>Nonaktif</option></select></label><label>Regex validasi (opsional)<input name="validation_regex" maxlength="500" value="<?=admin_escape($edit['validation_regex']??'')?>" placeholder="^[0-9]{5,20}$"></label></div>
<p><label class="check"><input type="checkbox" name="is_required" value="1" <?=!isset($edit['is_required'])||(int)$edit['is_required']===1?'checked':''?>> Wajib diisi</label></p>
<label>Opsi dropdown (satu per baris: <code>nilai|Label</code>)<textarea name="options" placeholder="asia|Asia&#10;america|Amerika&#10;europe|Eropa"><?=admin_escape($optionsText)?></textarea></label><p class="small muted">Hanya dipakai untuk tipe Dropdown. Dropdown aktif wajib memiliki minimal satu opsi. Opsi lama yang tidak dipakai dinonaktifkan, tidak dihapus.</p><button type="submit" <?=$canManage?'':'disabled'?>>Simpan konfigurasi</button> <?php if($edit):?><a href="/admin/game-fields.php?game_id=<?=$gameId?>">Batal</a><?php endif;?></form></section>
<section class="panel"><h2>Kolom yang sudah dibuat</h2><?php if(!$fields):?><p class="muted">Belum ada kolom untuk game ini.</p><?php else:?><div class="table-wrap"><table><thead><tr><th>Urutan</th><th>Kunci</th><th>Label</th><th>Tipe</th><th>Wajib</th><th>Status</th><th>Aksi</th></tr></thead><tbody><?php foreach($fields as $f):?><tr><td><?=admin_escape($f['sort_order'])?></td><td><code><?=admin_escape($f['field_key'])?></code></td><td><?=admin_escape($f['label'])?></td><td><?=admin_escape($f['field_type'])?></td><td><?=$f['is_required']?'Ya':'Tidak'?></td><td><?=admin_escape($f['status'])?></td><td><div class="actions"><a href="/admin/game-fields.php?game_id=<?=$gameId?>&edit=<?=$f['id']?>">Ubah</a><form method="post"><input type="hidden" name="csrf" value="<?=admin_escape($csrf)?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=$f['id']?>"><button class="secondary" type="submit" <?=$canManage?'':'disabled'?>><?=$f['status']==='ACTIVE'?'Nonaktifkan':'Aktifkan'?></button></form></div></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
<p class="small muted">Penting: halaman ini mengatur tampilan kolom dan validasi input dasar, bukan pengecekan nama akun ke server game. Validasi server dan pemetaan field ke masing-masing supplier masih harus diuji sebelum checkout/pemesanan diaktifkan.</p>
</main></body></html>
