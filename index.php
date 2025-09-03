<?php
/*****************************************************
 * HadithLib Pro — Single-file PHP App (SQLite)
 * فارسی + RTL + پنل مدیریت + جستجو + دسته‌بندی + اذکار هفته (تقویم ایران)
 * ایمپورت/اکسپورت CSV برای داده‌های حجیم
 * نیازمندی‌ها: PHP 7.4+ با SQLite3 فعال. بدون Composer.
 * دیپلوی: کپی همین index.php روی هاست PHP کافیست.
 *****************************************************/

mb_internal_encoding("UTF-8");
date_default_timezone_set("Asia/Tehran");
session_start();

define('APP_TITLE', 'کتابخانه احادیث | HadithLib Pro');
define('DB_FILE', __DIR__ . '/data.sqlite');
define('ADMIN_USER', 'admin');               // پیش‌فرض — بعداً تغییر دهید
define('ADMIN_PASS', 'admin123');
define('CSRF_KEY', '_csrf');

$db = new SQLite3(DB_FILE);
$db->exec('PRAGMA foreign_keys = ON;');
$db->exec('PRAGMA journal_mode = WAL;');

/* ---- ساخت جداول ---- */
$db->exec("CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT UNIQUE NOT NULL,
  password_hash TEXT NOT NULL,
  created_at TEXT NOT NULL
);");

$db->exec("CREATE TABLE IF NOT EXISTS persons (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug TEXT UNIQUE NOT NULL,
  name TEXT NOT NULL,
  created_at TEXT NOT NULL
);");

$db->exec("CREATE TABLE IF NOT EXISTS hadiths (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  person_id INTEGER NOT NULL,
  text_ar TEXT NOT NULL,
  text_fa TEXT,
  source TEXT,
  tags TEXT,
  created_at TEXT NOT NULL,
  FOREIGN KEY(person_id) REFERENCES persons(id) ON DELETE CASCADE
);");

$db->exec("CREATE TABLE IF NOT EXISTS azkar (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  day_index INTEGER NOT NULL, -- 0=شنبه .. 6=جمعه
  text TEXT NOT NULL
);");

/* ---- داده‌های اولیه ---- */
if ($db->querySingle("SELECT COUNT(*) FROM users")==0){
  $stmt=$db->prepare("INSERT INTO users(username,password_hash,created_at) VALUES(:u,:p,:c)");
  $stmt->bindValue(':u', ADMIN_USER, SQLITE3_TEXT);
  $stmt->bindValue(':p', password_hash(ADMIN_PASS, PASSWORD_BCRYPT), SQLITE3_TEXT);
  $stmt->bindValue(':c', date('c'), SQLITE3_TEXT);
  $stmt->execute();
}

if ($db->querySingle("SELECT COUNT(*) FROM persons")==0){
  $persons=[
    ['prophet','پیامبر اکرم ﷺ'],
    ['imam-ali','امیرالمؤمنین علی(ع)'],['imam-hasan','امام حسن(ع)'],['imam-husayn','امام حسین(ع)'],
    ['imam-sajjad','امام سجاد(ع)'],['imam-baqir','امام باقر(ع)'],['imam-sadiq','امام صادق(ع)'],
    ['imam-kazim','امام کاظم(ع)'],['imam-rida','امام رضا(ع)'],['imam-jawad','امام جواد(ع)'],
    ['imam-hadi','امام هادی(ع)'],['imam-askari','امام عسکری(ع)'],['imam-mahdi','حضرت ولی‌عصر(عج)'],
    // انبیا
    ['nabi-adam','حضرت آدم(ع)'],['nabi-idris','حضرت ادریس(ع)'],['nabi-nuh','حضرت نوح(ع)'],
    ['nabi-ibrahim','حضرت ابراهیم(ع)'],['nabi-lut','حضرت لوط(ع)'],['nabi-ismail','حضرت اسماعیل(ع)'],
    ['nabi-ishaq','حضرت اسحاق(ع)'],['nabi-yaqub','حضرت یعقوب(ع)'],['nabi-yusuf','حضرت یوسف(ع)'],
    ['nabi-ayub','حضرت ایوب(ع)'],['nabi-shuayb','حضرت شعیب(ع)'],['nabi-musa','حضرت موسی(ع)'],
    ['nabi-harun','حضرت هارون(ع)'],['nabi-dawud','حضرت داوود(ع)'],['nabi-sulayman','حضرت سلیمان(ع)'],
    ['nabi-ilyas','حضرت الیاس(ع)'],['nabi-al-yasa','حضرت الیسع(ع)'],['nabi-dhul-kifl','حضرت ذوالکفل(ع)'],
    ['nabi-yunus','حضرت یونس(ع)'],['nabi-zakariya','حضرت زکریا(ع)'],['nabi-yahya','حضرت یحیی(ع)'],
    ['nabi-isa','حضرت عیسی(ع)'],
  ];
  $stmt=$db->prepare("INSERT INTO persons(slug,name,created_at) VALUES(:s,:n,:c)");
  foreach($persons as $p){
    $stmt->bindValue(':s',$p[0],SQLITE3_TEXT);
    $stmt->bindValue(':n',$p[1],SQLITE3_TEXT);
    $stmt->bindValue(':c',date('c'),SQLITE3_TEXT);
    $stmt->execute();
  }
}

if ($db->querySingle("SELECT COUNT(*) FROM azkar")==0){
  $azk=[ [0,'سُبحانَ الله'],[1,'الحمدُ لله'],[2,'لا إلهَ إلّا الله'],[3,'اللهُ أکبر'],[4,'لا حولَ و لا قوّةَ إلّا بالله'],[5,'اللهمّ صلّ علی محمد و آل محمد'],[6,'أستغفرُ اللهَ رَبّي وأتوبُ إلیه'] ];
  $stmt=$db->prepare("INSERT INTO azkar(day_index,text) VALUES(:d,:t)");
  foreach($azk as $z){
    $stmt->bindValue(':d',$z[0],SQLITE3_INTEGER);
    $stmt->bindValue(':t',$z[1],SQLITE3_TEXT);
    $stmt->execute();
  }
}

/* ---- کمک‌ها ---- */
function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf_token(){ if(empty($_SESSION[CSRF_KEY])) $_SESSION[CSRF_KEY]=bin2hex(random_bytes(16)); return $_SESSION[CSRF_KEY]; }
function csrf_check(){ if($_SERVER['REQUEST_METHOD']==='POST'){ if(!isset($_POST[CSRF_KEY])||!hash_equals($_SESSION[CSRF_KEY]??'',$_POST[CSRF_KEY])){ http_response_code(400); exit('CSRF token mismatch'); } } }
function is_admin(){ return !empty($_SESSION['uid']); }
function require_admin(){ if(!is_admin()){ header('Location: ?admin=login'); exit; } }
function jalali_week_index(){ $w=(int)date('w'); $map=[1,2,3,4,5,6,0]; return $map[$w]; }

/* ---- روتینگ ---- */
$action = $_GET['a'] ?? 'home';
$admin  = $_GET['admin'] ?? null;

/* ---- مدیریت ---- */
if ($admin === 'login'){
  if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();
    $u=$_POST['username']??''; $p=$_POST['password']??'';
    $stmt=$db->prepare("SELECT id,password_hash FROM users WHERE username=:u LIMIT 1");
    $stmt->bindValue(':u',$u,SQLITE3_TEXT);
    $res=$stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if($res && password_verify($p,$res['password_hash'])){ $_SESSION['uid']=(int)$res['id']; header('Location: ?admin=dashboard'); exit; }
    else $error='نام کاربری یا رمز عبور نادرست است.';
  }
  echo tpl('ورود مدیر', view_login(isset($error)?$error:null)); exit;
}

if ($admin === 'logout'){ session_destroy(); header('Location: ?'); exit; }

if ($admin === 'dashboard'){ require_admin(); echo tpl('پنل مدیریت', view_dashboard()); exit; }

if ($admin === 'hadith-create'){
  require_admin();
  if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();
    $pid=(int)($_POST['person_id']??0);
    $ar=trim($_POST['text_ar']??''); $fa=trim($_POST['text_fa']??''); $src=trim($_POST['source']??''); $tags=trim($_POST['tags']??'');
    if($pid && $ar){
      $stmt=$db->prepare("INSERT INTO hadiths(person_id,text_ar,text_fa,source,tags,created_at) VALUES(:p,:ar,:fa,:s,:t,:c)");
      $stmt->bindValue(':p',$pid,SQLITE3_INTEGER); $stmt->bindValue(':ar',$ar,SQLITE3_TEXT); $stmt->bindValue(':fa',$fa,SQLITE3_TEXT);
      $stmt->bindValue(':s',$src,SQLITE3_TEXT); $stmt->bindValue(':t',$tags,SQLITE3_TEXT); $stmt->bindValue(':c',date('c'),SQLITE3_TEXT);
      $stmt->execute(); header('Location: ?admin=dashboard&ok=1'); exit;
    } else $error='فیلدهای ضروری را پر کنید.';
  }
  echo tpl('افزودن حدیث', view_hadith_form(null, isset($error)?$error:null)); exit;
}

if ($admin === 'hadith-edit'){
  require_admin();
  $id=(int)($_GET['id']??0);
  $had=$db->query("SELECT * FROM hadiths WHERE id=$id")->fetchArray(SQLITE3_ASSOC);
  if(!$had){ http_response_code(404); exit('Not found'); }
  if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();
    $pid=(int)($_POST['person_id']??0); $ar=trim($_POST['text_ar']??''); $fa=trim($_POST['text_fa']??''); $src=trim($_POST['source']??''); $tags=trim($_POST['tags']??'');
    $stmt=$db->prepare("UPDATE hadiths SET person_id=:p,text_ar=:ar,text_fa=:fa,source=:s,tags=:t WHERE id=:id");
    $stmt->bindValue(':p',$pid,SQLITE3_INTEGER); $stmt->bindValue(':ar',$ar,SQLITE3_TEXT); $stmt->bindValue(':fa',$fa,SQLITE3_TEXT);
    $stmt->bindValue(':s',$src,SQLITE3_TEXT); $stmt->bindValue(':t',$tags,SQLITE3_TEXT); $stmt->bindValue(':id',$id,SQLITE3_INTEGER);
    $stmt->execute(); header('Location: ?admin=dashboard&ok=1'); exit;
  }
  echo tpl('ویرایش حدیث', view_hadith_form($had)); exit;
}

if ($admin === 'hadith-delete'){
  require_admin(); csrf_check();
  $id=(int)($_POST['id']??0); $db->exec("DELETE FROM hadiths WHERE id=$id"); header('Location: ?admin=dashboard&ok=1'); exit;
}

if ($admin === 'persons'){
  require_admin();
  if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();
    $slug=trim($_POST['slug']??''); $name=trim($_POST['name']??'');
    if($slug && $name){
      $stmt=$db->prepare("INSERT INTO persons(slug,name,created_at) VALUES(:s,:n,:c)");
      $stmt->bindValue(':s',$slug,SQLITE3_TEXT); $stmt->bindValue(':n',$name,SQLITE3_TEXT); $stmt->bindValue(':c',date('c'),SQLITE3_TEXT);
      $stmt->execute();
    }
  }
  echo tpl('مدیریت اشخاص', view_persons()); exit;
}

if ($admin === 'azkar'){
  require_admin();
  if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();
    $id=(int)($_POST['id']??0); $text=trim($_POST['text']??'');
    if($id && $text){
      $stmt=$db->prepare("UPDATE azkar SET text=:t WHERE id=:i");
      $stmt->bindValue(':t',$text,SQLITE3_TEXT); $stmt->bindValue(':i',$id,SQLITE3_INTEGER); $stmt->execute();
    }
  }
  echo tpl('مدیریت اذکار', view_azkar()); exit;
}

/* ---- Import/Export CSV ---- */
if ($admin === 'import'){
  require_admin();
  if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();
    if(isset($_FILES['csv']) && $_FILES['csv']['error']===UPLOAD_ERR_OK){
      $fp = fopen($_FILES['csv']['tmp_name'],'r');
      $db->exec('BEGIN');
      $insH = $db->prepare("INSERT INTO hadiths(person_id,text_ar,text_fa,source,tags,created_at) VALUES(:p,:ar,:fa,:s,:t,:c)");
      while(($row = fgetcsv($fp, 0, ",")) !== false){
        if(count($row)<5) continue;
        list($slug,$ar,$fa,$src,$tags) = $row;
        $pid = $db->querySingle("SELECT id FROM persons WHERE slug='".SQLite3::escapeString($slug)."'");
        if(!$pid) continue;
        $insH->bindValue(':p',$pid,SQLITE3_INTEGER);
        $insH->bindValue(':ar',$ar,SQLITE3_TEXT);
        $insH->bindValue(':fa',$fa,SQLITE3_TEXT);
        $insH->bindValue(':s',$src,SQLITE3_TEXT);
        $insH->bindValue(':t',$tags,SQLITE3_TEXT);
        $insH->bindValue(':c',date('c'),SQLITE3_TEXT);
        $insH->execute();
      }
      $db->exec('COMMIT');
      fclose($fp);
      $msg='ایمپورت با موفقیت انجام شد.';
    } else $msg='فایل CSV معتبر نیست.';
  }
  echo tpl('ایمپورت CSV', view_import(isset($msg)?$msg:null)); exit;
}

if ($admin === 'export'){
  require_admin();
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=hadiths_export.csv');
  $out = fopen('php://output', 'w');
  // Header
  fputcsv($out, ['person_slug','text_ar','text_fa','source','tags']);
  $res=$db->query("SELECT h.text_ar,h.text_fa,h.source,h.tags,p.slug FROM hadiths h JOIN persons p ON p.id=h.person_id ORDER BY h.id");
  while($r=$res->fetchArray(SQLITE3_ASSOC)){
    fputcsv($out, [$r['slug'],$r['text_ar'],$r['text_fa'],$r['source'],$r['tags']]);
  }
  fclose($out); exit;
}

/* ---- صفحات عمومی ---- */
if ($action === 'search'){ $q=trim($_GET['q']??''); echo tpl('جستجو', view_search($q)); exit; }
if ($action === 'person'){ $slug=trim($_GET['slug']??''); echo tpl('احادیث', view_person($slug)); exit; }
echo tpl('خانه', view_home()); exit;

/* ======================== Views & UI ======================== */
function base_css(){ return <<<CSS
:root{--g1:#0b3d2b;--g2:#1f8a54;--g3:#35c16b;--glass:rgba(255,255,255,.1);--bd:rgba(255,255,255,.25);--txt:#fff}
*{box-sizing:border-box}
body{margin:0;color:var(--txt);font-family:'Shahrazad',serif;background:
  radial-gradient(1200px 600px at 10% -10%, rgba(255,255,255,.12), transparent 60%),
  radial-gradient(1000px 500px at 110% 10%, rgba(255,255,255,.1), transparent 60%),
  linear-gradient(135deg,var(--g1),var(--g2) 55%,var(--g3));min-height:100vh}
a{color:#fff;text-decoration:none}
.container{max-width:1100px;margin:24px auto;padding:0 16px 64px}
.hdr{position:sticky;top:0;z-index:10;backdrop-filter:blur(8px);background:linear-gradient(180deg,rgba(0,0,0,.25),rgba(0,0,0,.05));border-bottom:1px solid var(--bd);display:flex;align-items:center;justify-content:space-between;padding:12px 16px}
.brand{display:flex;align-items:center;gap:10px}
.logo{width:40px;height:40px;border-radius:12px;display:grid;place-items:center;background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.35)}
.title{font-weight:700}
.tabs{display:flex;gap:12px;flex-wrap:wrap}
.card{background:var(--glass);border:1px solid var(--bd);border-radius:20px;padding:18px 16px;margin:12px 0;box-shadow:0 10px 20px rgba(0,0,0,.15)}
.grid{display:grid;gap:12px}
@media(min-width:900px){.grid-2{grid-template-columns:1.3fr 1fr}}
input,textarea,select,button{width:100%;padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.3);background:rgba(255,255,255,.08);color:#fff;font-family:'Shahrazad',serif;font-size:1.05rem}
button{cursor:pointer;background:#fff;color:#0f5132;border-color:#fff}
button.ghost{background:transparent;color:#fff;border-color:rgba(255,255,255,.4)}
.list{display:grid;gap:10px}
.pill{display:inline-block;padding:.15rem .6rem;border:1px solid rgba(255,255,255,.35);border-radius:999px;font-size:.95rem;margin:2px}
.flex{display:flex;gap:10px;flex-wrap:wrap}
.hadith{line-height:2}
.small{opacity:.9;font-size:.95rem}
.table{width:100%;border-collapse:collapse}
.table th,.table td{padding:8px;border-bottom:1px dashed rgba(255,255,255,.3)}
.badge{display:inline-block;padding:.15rem .5rem;border-radius:999px;background:rgba(255,255,255,.15);border:1px dashed rgba(255,255,255,.35)}
.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.rt{text-align:right}
.warn{border:1px solid #ffb3b3;background:rgba(255,0,0,.08);padding:8px;border-radius:12px}
CSS; }

function tpl($title, $content){
  $css = base_css();
  $year=date('Y');
  $app=APP_TITLE;
  return <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$app} — {$title}</title>
<link href="https://fonts.googleapis.com/css2?family=Shahrazad:wght@400;700&display=swap" rel="stylesheet">
<style>{$css}</style>
</head>
<body>
<header class="hdr">
  <div class="brand"><div class="logo">🕊️</div><div class="title">{$app}</div></div>
  <nav class="tabs">
    <a href="?">خانه</a>
    <a href="?a=search">جستجو</a>
    <a href="?admin=dashboard">مدیریت</a>
  </nav>
</header>
<main class="container">
  {$content}
</main>
<footer class="ftr" style="text-align:center;opacity:.9;padding:24px">© {$year}</footer>
</body>
</html>
HTML;
}

/* ---- Views ---- */
function view_home(){
  global $db;
  $count = $db->querySingle("SELECT COUNT(*) FROM hadiths");
  $daily = '<div class="small">برای آغاز، از پنل مدیریت حدیث اضافه کنید.</div>';
  if($count>0){
    $offset = (int)(floor(time()/86400) % $count);
    $res = $db->query("SELECT h.*,p.name as person_name FROM hadiths h JOIN persons p ON p.id=h.person_id LIMIT 1 OFFSET $offset")->fetchArray(SQLITE3_ASSOC);
    $daily = hadith_card($res);
  }
  $d = jalali_week_index();
  $stmt=$db->prepare("SELECT * FROM azkar WHERE day_index=:d"); $stmt->bindValue(':d',$d,SQLITE3_INTEGER);
  $az = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
  $persons=[]; $q=$db->query("SELECT * FROM persons ORDER BY name"); while($r=$q->fetchArray(SQLITE3_ASSOC)){ $persons[]=$r; }
  $chips = '<div class="flex">'.implode('', array_map(fn($p)=>'<a class="pill" href="?a=person&slug='.e($p['slug']).'">'.e($p['name']).'</a>', $persons)).'</div>';
  $search = '<form class="card" method="get"><input type="hidden" name="a" value="search"><input name="q" placeholder="جستجو در متن/ترجمه/منبع/برچسب…"><div class="actions"><button>جستجو</button></div></form>';
  return <<<HTML
<div class="grid grid-2">
  <div class="card"><h2>حدیث امروز ✨</h2>{$daily}<div class="small">یک حدیث در روز به‌صورت خودکار نمایش داده می‌شود.</div></div>
  <div class="card"><h2>ذکر امروز 📿</h2><div class="hadith">{$az['text']}</div><div class="small">(مطابق روزهای هفتهٔ ایران)</div></div>
</div>
<div class="card"><h2>دسته‌بندی‌ها</h2>{$chips}</div>
{$search}
HTML;
}

function hadith_card($h){
  if(!$h) return '';
  $tagsHtml=''; if(!empty($h['tags'])){ $tags=array_filter(array_map('trim', explode(',',$h['tags']))); $tagsHtml=implode(' ', array_map(fn($t)=>'<span class="pill">#'.e($t).'</span>',$tags)); }
  $src = !empty($h['source'])?'<span class="pill">منبع: '.e($h['source']).'</span>':'';
  return '<div class="card"><div class="hadith"><div style="font-size:1.15rem">'.e($h['text_ar']).'</div>'.(!empty($h['text_fa'])?'<div style="opacity:.95;margin-top:6px">'.e($h['text_fa']).'</div>':'').'</div><div class="small flex">'.$src.$tagsHtml.'</div><div class="small">— '.e($h['person_name']??'').'</div></div>';
}

function view_person($slug){
  global $db;
  $stmt=$db->prepare("SELECT id,name FROM persons WHERE slug=:s LIMIT 1");
  $stmt->bindValue(':s',$slug,SQLITE3_TEXT); $p=$stmt->execute()->fetchArray(SQLITE3_ASSOC);
  if(!$p) return '<div class="card">دسته یافت نشد.</div>';
  $items=[]; $q=$db->query("SELECT h.*,p.name as person_name FROM hadiths h JOIN persons p ON p.id=h.person_id WHERE person_id=".$p['id']." ORDER BY h.id DESC");
  while($r=$q->fetchArray(SQLITE3_ASSOC)){ $items[] = hadith_card($r); }
  $list = $items ? implode('', $items) : '<div class="card">حدیثی ثبت نشده است.</div>';
  return '<div class="card"><h2>احادیث: '.e($p['name']).'</h2></div>'.$list;
}

function view_search($q){
  global $db;
  $q=trim($q);
  $form = '<form class="card" method="get"><input type="hidden" name="a" value="search"><input name="q" value="'.e($q).'" placeholder="کلمه یا عبارت را وارد کنید…"><div class="actions"><button>جستجو</button></div></form>';
  if($q==='') return $form.'<div class="card">برای جستجو عبارتی وارد کنید.</div>';
  $stmt=$db->prepare("SELECT h.*,p.name as person_name FROM hadiths h JOIN persons p ON p.id=h.person_id WHERE h.text_ar LIKE :q OR h.text_fa LIKE :q OR h.source LIKE :q OR h.tags LIKE :q ORDER BY h.id DESC");
  $stmt->bindValue(':q','%'.$q.'%',SQLITE3_TEXT); $res=$stmt->execute();
  $items=[]; while($r=$res->fetchArray(SQLITE3_ASSOC)){ $items[] = hadith_card($r); }
  $list = $items ? implode('', $items) : '<div class="card">نتیجه‌ای یافت نشد.</div>';
  return $form.$list;
}

function persons_options($selected=null){
  global $db;
  $opts=''; $q=$db->query("SELECT id,name FROM persons ORDER BY name");
  while($r=$q->fetchArray(SQLITE3_ASSOC)){ $sel=$selected==$r['id']?' selected':''; $opts.='<option value="'.$r['id'].'"'.$sel.'>'.e($r['name']).'</option>'; }
  return $opts;
}

function view_hadith_form($had=null,$error=null){
  $err = $error ? '<div class="warn">'.e($error).'</div>' : '';
  $csrf=e(csrf_token());
  $action = $had ? '?admin=hadith-edit&id='.$had['id'] : '?admin=hadith-create';
  $person_id=$had['person_id']??''; $ar=$had['text_ar']??''; $fa=$had['text_fa']??''; $src=$had['source']??''; $tags=$had['tags']??'';
  $del = $had ? '<form method="post" action="?admin=hadith-delete" onsubmit="return confirm(\'حذف شود؟\')" class="flex"><input type="hidden" name="_csrf" value="'.$csrf.'"><input type="hidden" name="id" value="'.$had['id'].'"><button class="ghost">حذف</button></form>' : '';
  return <<<HTML
<div class="card">
  <h2>{$had?'ویرایش حدیث':'افزودن حدیث'}</h2>
  {$err}
  <form method="post" action="{$action}">
    <input type="hidden" name="_csrf" value="{$csrf}">
    <label>شخص/دسته</label>
    <select name="person_id" required>
      <option value="">— انتخاب کنید —</option>
      {persons_options}
    </select>
    <label>متن عربی/روایی</label>
    <textarea name="text_ar" rows="4" required placeholder="متن حدیث...">{$ar}</textarea>
    <label>ترجمه فارسی</label>
    <textarea name="text_fa" rows="3" placeholder="ترجمه...">{$fa}</textarea>
    <label>منبع</label>
    <input name="source" value="{$src}" placeholder="مثلاً: الکافی، ج۲، ص۳۸">
    <label>برچسب‌ها (با ویرگول جدا)</label>
    <input name="tags" value="{$tags}" placeholder="ایمان، اخلاق، ...">
    <div class="actions"><button>ذخیره</button><a class="ghost badge" href="?admin=dashboard">انصراف</a></div>
  </form>
  {$del}
</div>
HTML;
}

function view_persons(){
  global $db;
  $csrf=e(csrf_token());
  $rows=''; $q=$db->query("SELECT id,slug,name FROM persons ORDER BY name");
  while($r=$q->fetchArray(SQLITE3_ASSOC)){ $rows.='<tr><td class="rt">'.e($r['id']).'</td><td>'.e($r['slug']).'</td><td>'.e($r['name']).'</td></tr>'; }
  $table = '<table class="table"><thead><tr><th>#</th><th>slug</th><th>نام</th></tr></thead><tbody>'.$rows.'</tbody></table>';
  $form = <<<HTML
<form class="card" method="post">
  <input type="hidden" name="_csrf" value="{$csrf}">
  <h3>افزودن شخص جدید</h3>
  <input name="slug" placeholder="مثلاً imam-ali" required>
  <input name="name" placeholder="مثلاً امیرالمؤمنین علی(ع)" required>
  <div class="actions"><button>افزودن</button></div>
</form>
HTML;
  return '<div class="card"><h2>اشخاص</h2></div>'.$table.$form;
}

function view_azkar(){
  global $db;
  $csrf=e(csrf_token());
  $rows=''; $days=['شنبه','یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه','جمعه'];
  $q=$db->query("SELECT * FROM azkar ORDER BY day_index");
  while($r=$q->fetchArray(SQLITE3_ASSOC)){
    $rows.='<tr><td>'.e($days[$r['day_index']]).'</td><td>'.e($r['text']).'</td><td>
      <form method="post" class="flex">
        <input type="hidden" name="_csrf" value="'.$csrf.'">
        <input type="hidden" name="id" value="'.$r['id'].'">
        <input name="text" value="'.e($r['text']).'">
        <button>ذخیره</button>
      </form>
    </td></tr>';
  }
  return '<div class="card"><h2>اذکار هفته (تقویم ایران)</h2><table class="table"><thead><tr><th>روز</th><th>ذکر فعلی</th><th>ویرایش</th></tr></thead><tbody>'.$rows.'</tbody></table></div>';
}

function view_login($error=null){
  $err = $error ? '<div class="warn">'.e($error).'</div>' : '';
  $csrf=e(csrf_token());
  return <<<HTML
<form class="card" method="post">
  {$err}
  <input type="hidden" name="_csrf" value="{$csrf}">
  <h2>ورود به پنل مدیریت</h2>
  <input name="username" placeholder="نام کاربری" required>
  <input name="password" type="password" placeholder="رمز عبور" required>
  <div class="actions"><button>ورود</button></div>
</form>
HTML;
}

function view_import($msg=null){
  $csrf=e(csrf_token());
  $notice = $msg ? '<div class="card">'.$msg.'</div>' : '';
  $help = '<div class="small">فرمت CSV: <code>person_slug,text_ar,text_fa,source,tags</code> — نمونه فایل: <a class="badge" href="sample.csv" download>sample.csv</a></div>';
  return <<<HTML
<div class="card">
  <h2>ایمپورت CSV احادیث</h2>
  {$notice}
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="{$csrf}">
    <input type="file" name="csv" accept=".csv" required>
    <div class="actions"><button>آپلود و ایمپورت</button><a class="ghost badge" href="?admin=dashboard">بازگشت</a></div>
  </form>
  {$help}
</div>
HTML;
}

function view_dashboard(){
  global $db;
  $ok = isset($_GET['ok']) ? '<div class="card badge">عملیات با موفقیت انجام شد.</div>' : '';
  $rows=''; $q=$db->query("SELECT h.id,h.text_ar,p.name as person_name FROM hadiths h JOIN persons p ON p.id=h.person_id ORDER BY h.id DESC LIMIT 20");
  while($r=$q->fetchArray(SQLITE3_ASSOC)){
    $rows.='<tr><td class="rt">'.e($r['id']).'</td><td>'.e(mb_strimwidth($r['text_ar'],0,140,'…','UTF-8')).'</td><td>'.e($r['person_name']).'</td><td class="rt"><a class="badge" href="?admin=hadith-edit&id='.$r['id'].'">ویرایش</a></td></tr>';
  }
  if(!$rows) $rows = '<tr><td colspan="4">حدیثی ثبت نشده.</td></tr>';
  return <<<HTML
<div class="card">
  <div class="flex" style="justify-content:space-between;align-items:center">
    <h2>داشبورد مدیریت</h2>
    <div class="flex">
      <a class="badge" href="?admin=hadith-create">+ افزودن حدیث</a>
      <a class="badge" href="?admin=persons">اشخاص</a>
      <a class="badge" href="?admin=azkar">اذکار هفته</a>
      <a class="badge" href="?admin=import">ایمپورت CSV</a>
      <a class="badge" href="?admin=export">اکسپورت CSV</a>
      <a class="badge" href="?admin=logout">خروج</a>
    </div>
  </div>
  {$ok}
  <table class="table">
    <thead><tr><th>#</th><th>حدیث</th><th>شخص</th><th>عملیات</th></tr></thead>
    <tbody>{$rows}</tbody>
  </table>
</div>
HTML;
}
/* ======================== End ======================== */
