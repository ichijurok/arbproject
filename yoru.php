<?php
/*
 * Simple PHP File Manager — no login.
 *
 * Anyone with the URL has full read/write/delete access to every file the
 * PHP user can reach. Rename this file to something random, keep it in its
 * own directory, and delete it when you are done.
 *
 * Requires PHP 8.1+ (respond(): never, str_starts_with, str_contains).
 */
declare(strict_types=1);

/* ------------------------------------------------------------------
 * Block cross-site POSTs. No session, no token — just the browser's
 * own origin metadata. Stops a page you happen to visit from firing
 * ?action=delete / ?action=save at this script.
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $site   = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $host   = $_SERVER['HTTP_HOST'] ?? '';

    $sameOrigin = $site !== ''
        ? $site === 'same-origin'
        : ($origin === ''
            || strcasecmp($origin, 'https://' . $host) === 0
            || strcasecmp($origin, 'http://'  . $host) === 0);

    if (!$sameOrigin) {
        http_response_code(403);
        exit('Cross-site request blocked.');
    }
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");
header('Strict-Transport-Security: max-age=31536000');

/* The directory this script lives in — used as the start folder. */
$homeDir = __DIR__;   // <-- CHANGE 1

function respond(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function normalisePath(string $path): string {
    $path = str_replace("\0", '', $path);

    // On Windows only: treat both separators as separators. On POSIX a
    // backslash is a legal filename character and must be left alone.
    if (DIRECTORY_SEPARATOR === '\\') {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    if ($path === '' || $path[0] !== DIRECTORY_SEPARATOR) {
        $path = DIRECTORY_SEPARATOR . $path;
    }

    $parts = [];
    foreach (explode(DIRECTORY_SEPARATOR, $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($parts);
        } else {
            $parts[] = $part;
        }
    }
    return DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
}

/* Resolve the full path, following symlinks. Correct for read/save/list/
 * download, where you want the real target. */
function existingPath(string $path): string {
    $real = realpath(normalisePath($path));
    if ($real === false) {
        throw new RuntimeException('The requested path does not exist.');
    }
    return $real;
}

/* Resolve the PARENT only, never the final component. Required for
 * rename/delete: realpath() on a symlink returns its target, so deleting
 * a link would delete what it points at and leave the link dangling. */
function pathForMutation(string $path): string {
    $normalised = normalisePath($path);
    $parent = realpath(dirname($normalised));
    if ($parent === false) {
        throw new RuntimeException('The requested path does not exist.');
    }
    $target = $parent . DIRECTORY_SEPARATOR . basename($normalised);
    if (!file_exists($target) && !is_link($target)) {
        throw new RuntimeException('The requested path does not exist.');
    }
    return $target;
}

function checkedName(string $name): string {
    $name = trim($name);
    if ($name === '' || $name === '.' || $name === '..' ||
        str_contains($name, '/') || str_contains($name, '\\') ||
        str_contains($name, "\0")) {
        throw new RuntimeException('Enter a valid file or folder name.');
    }
    return $name;
}

function readableDirectory(string $path): string {
    $real = existingPath($path);
    if (!is_dir($real) || !is_readable($real)) {
        throw new RuntimeException('This directory cannot be opened.');
    }
    return $real;
}

function removeItem(string $path): void {
    if (is_link($path) || is_file($path)) {
        if (!unlink($path)) {
            throw new RuntimeException('Could not delete the file.');
        }
        return;
    }

    if (!is_dir($path)) {
        throw new RuntimeException('Item not found.');
    }

    foreach (new DirectoryIterator($path) as $item) {
        if ($item->isDot()) {
            continue;
        }
        removeItem($path . DIRECTORY_SEPARATOR . $item->getFilename());
    }

    if (!rmdir($path)) {
        throw new RuntimeException('Could not delete the folder.');
    }
}

function readableSize(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $size = (float)$bytes;
    $unit = 0;

    while ($size >= 1024 && $unit < count($units) - 1) {
        $size /= 1024;
        $unit++;
    }

    return $unit === 0 ? $bytes . ' B' : number_format($size, 1) . ' ' . $units[$unit];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    try {
        $action = (string)$_GET['action'];
        $path = (string)($_POST['path'] ?? $homeDir);

        if ($action === 'list') {
            $directory = readableDirectory($path);
            $entries = [];

            foreach (new DirectoryIterator($directory) as $entry) {
                if ($entry->isDot()) {
                    continue;
                }

                $fullPath = $directory . DIRECTORY_SEPARATOR . $entry->getFilename();
                $isLink = $entry->isLink();
                $isDirectory = $entry->isDir() && !$isLink;

                $entries[] = [
                    'name' => $entry->getFilename(),
                    'path' => $fullPath,
                    'type' => $isLink ? 'link' : ($isDirectory ? 'folder' : 'file'),
                    'size' => $isDirectory ? '—' : readableSize((int)@filesize($fullPath)),
                    'modified' => @filemtime($fullPath) ? date('Y-m-d H:i', (int)filemtime($fullPath)) : '—'
                ];
            }

            usort($entries, static function (array $a, array $b): int {
                if ($a['type'] === 'folder' && $b['type'] !== 'folder') return -1;
                if ($a['type'] !== 'folder' && $b['type'] === 'folder') return 1;
                return strnatcasecmp($a['name'], $b['name']);
            });

            respond([
                'ok' => true,
                'directory' => $directory,
                'parent' => dirname($directory),
                'entries' => $entries
            ]);
        }

        if ($action === 'read') {
            $file = existingPath($path);
            if (!is_file($file) || !is_readable($file)) {
                throw new RuntimeException('This file cannot be opened.');
            }
            if (filesize($file) > 1024 * 1024) {
                throw new RuntimeException('Files larger than 1 MB cannot be edited in the browser.');
            }
            $content = file_get_contents($file);
            if ($content === false) {
                throw new RuntimeException('Could not read the file.');
            }
            respond(['ok' => true, 'content' => $content]);
        }

        if ($action === 'save') {
            $file = existingPath($path);
            if (!is_file($file) || !is_writable($file)) {
                throw new RuntimeException('This file cannot be edited.');
            }
            $content = (string)($_POST['content'] ?? '');
            if (strlen($content) > 1024 * 1024) {
                throw new RuntimeException('The editor limit is 1 MB.');
            }
            if (file_put_contents($file, $content, LOCK_EX) === false) {
                throw new RuntimeException('Could not save the file.');
            }
            respond(['ok' => true, 'message' => 'File saved.']);
        }

        if ($action === 'create-file' || $action === 'create-folder') {
            $directory = readableDirectory($path);
            if (!is_writable($directory)) {
                throw new RuntimeException('This directory is not writable.');
            }
            $name = checkedName((string)($_POST['name'] ?? ''));
            $newPath = $directory . DIRECTORY_SEPARATOR . $name;

            if (file_exists($newPath) || is_link($newPath)) {
                throw new RuntimeException('An item with that name already exists.');
            }

            $success = $action === 'create-folder'
                ? mkdir($newPath, 0755)
                : file_put_contents($newPath, '') !== false;

            if (!$success) {
                throw new RuntimeException('Could not create the item.');
            }
            respond(['ok' => true, 'message' => 'Created successfully.']);
        }

        if ($action === 'rename') {
            $oldPath = pathForMutation($path);
            if ($oldPath === DIRECTORY_SEPARATOR) {
                throw new RuntimeException('The filesystem root cannot be renamed.');
            }

            $name = checkedName((string)($_POST['name'] ?? ''));
            $newPath = dirname($oldPath) . DIRECTORY_SEPARATOR . $name;

            if (file_exists($newPath) || is_link($newPath)) {
                throw new RuntimeException('An item with that name already exists.');
            }
            if (!rename($oldPath, $newPath)) {
                throw new RuntimeException('Could not rename the item.');
            }
            respond(['ok' => true, 'message' => 'Renamed successfully.']);
        }

        if ($action === 'delete') {
            $item = pathForMutation($path);
            if ($item === DIRECTORY_SEPARATOR) {
                throw new RuntimeException('The filesystem root cannot be deleted.');
            }
            removeItem($item);
            respond(['ok' => true, 'message' => 'Deleted successfully.']);
        }

        if ($action === 'upload') {
            $directory = readableDirectory($path);
            if (!is_writable($directory)) {
                throw new RuntimeException('This directory is not writable.');
            }
            if (!isset($_FILES['upload_files']['name']) ||
                !is_array($_FILES['upload_files']['name'])) {
                throw new RuntimeException('Choose one or more files first.');
            }

            /* Validate every file BEFORE moving any, so a bad name in the
             * middle of a batch does not leave half the upload behind. */
            $plan = [];
            foreach ($_FILES['upload_files']['name'] as $i => $originalName) {
                if (($_FILES['upload_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('An upload failed: ' . $originalName);
                }
                $name = checkedName(basename((string)$originalName));
                $destination = $directory . DIRECTORY_SEPARATOR . $name;

                if (file_exists($destination) || is_link($destination)) {
                    throw new RuntimeException('A file already exists: ' . $name);
                }
                $tmp = (string)$_FILES['upload_files']['tmp_name'][$i];
                if (!is_uploaded_file($tmp)) {
                    throw new RuntimeException('Could not upload: ' . $name);
                }
                $plan[] = [$name, $tmp, $destination];
            }

            $uploaded = [];
            foreach ($plan as [$name, $tmp, $destination]) {
                if (!move_uploaded_file($tmp, $destination)) {
                    throw new RuntimeException('Could not upload: ' . $name);
                }
                $uploaded[] = $name;
            }

            respond(['ok' => true, 'message' => 'Uploaded: ' . implode(', ', $uploaded)]);
        }

        if ($action === 'download') {
            $file = existingPath($path);
            if (!is_file($file) || !is_readable($file)) {
                throw new RuntimeException('Select a readable file.');
            }

            header('Content-Type: application/octet-stream');
            header('Content-Length: ' . filesize($file));
            header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode(basename($file)));
            readfile($file);
            exit;
        }

        throw new RuntimeException('Unknown operation.');
    } catch (Throwable $error) {
        respond(['ok' => false, 'error' => $error->getMessage()], 400);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Simple PHP File Manager</title>
<style>
:root{font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;color:#172033;background:#f4f6fb}
*{box-sizing:border-box}body{margin:0}button,input{font:inherit}button{border:0;border-radius:9px;padding:9px 13px;background:#eef1f7;color:#26334a;font-weight:600;cursor:pointer}button:hover{filter:brightness(.97)}.primary{background:#356df3;color:white}.danger{background:#fff0f1;color:#c52d3d}
header{height:66px;background:white;border-bottom:1px solid #e6eaf1;display:flex;align-items:center;padding:0 24px;font-weight:800;font-size:17px}.logo{width:34px;height:34px;border-radius:10px;background:#356df3;color:white;display:inline-grid;place-items:center;margin-right:10px}
main{max-width:1400px;margin:26px auto;padding:0 20px}.title{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px}.title h1{margin:0 0 4px;font-size:25px}.muted{color:#78849a;font-size:13px}
.panel{background:white;border:1px solid #e6eaf1;border-radius:14px;overflow:hidden;box-shadow:0 8px 30px #1c2d4a0b}
.toolbar{padding:14px;display:flex;gap:8px;flex-wrap:wrap;border-bottom:1px solid #edf0f5}.path{display:flex;gap:8px;padding:12px 14px;border-bottom:1px solid #edf0f5}.path input{min-width:0;flex:1;border:1px solid #e1e6ef;border-radius:8px;padding:9px 11px}
.tablewrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:620px}th{text-align:left;padding:12px 15px;color:#78849a;background:#fafbfe;font-size:11px;text-transform:uppercase;letter-spacing:.7px}td{padding:12px 15px;border-top:1px solid #eff1f5;font-size:13px}tr:hover td{background:#fbfcff}.file{display:flex;gap:10px;align-items:center}.ico{width:34px;height:34px;border-radius:9px;background:#eef3ff;display:grid;place-items:center;font-size:17px}.folder{background:#fff5dc}.filename{font-weight:650}.actions{display:flex;gap:5px;white-space:nowrap}.small{padding:6px 9px;font-size:12px}.empty{text-align:center;padding:48px;color:#78849a}
footer{padding:12px 15px;border-top:1px solid #edf0f5;color:#78849a;font-size:12px}
.modal-bg{position:fixed;inset:0;background:#111c32aa;display:none;align-items:center;justify-content:center;padding:16px}.modal{background:white;width:min(850px,100%);border-radius:14px;overflow:hidden}.modal-head,.modal-foot{padding:14px 17px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #edf0f5}.modal-foot{border:0;border-top:1px solid #edf0f5;justify-content:flex-end;gap:8px}.modal-body{padding:16px}.modal textarea{width:100%;height:430px;resize:vertical;border:1px solid #e1e6ef;border-radius:8px;padding:12px;font:13px/1.5 ui-monospace,Consolas,monospace}.toast{display:none;position:fixed;right:18px;bottom:18px;background:#172033;color:white;padding:12px 16px;border-radius:9px;max-width:90vw}.toast.error{background:#a52838}
@media(max-width:700px){main{padding:0 10px;margin:16px auto}.title{align-items:flex-start;flex-direction:column}header{padding:0 14px}}
</style>
</head>
<body>
<header><span class="logo">▤</span>Simple File Manager</header>
<main>
<div class="title"><div><h1>Files</h1><div class="muted">Browse and manage files and folders</div></div><button class="primary" onclick="document.getElementById('upload').click()">＋ Upload files</button></div>
<section class="panel">
<div class="toolbar">
<button onclick="createItem('create-folder')">＋ New folder</button>
<button onclick="createItem('create-file')">＋ New file</button>
<button onclick="goParent()">↑ Parent directory</button>
<button onclick="refresh()">↻ Refresh</button>
<input id="upload" type="file" multiple hidden onchange="uploadFiles(this.files)">
</div>
<div class="path"><input id="currentPath" value="<?= htmlspecialchars($homeDir, ENT_QUOTES) ?>" onkeydown="if(event.key==='Enter')go(this.value)"><button onclick="go(document.getElementById('currentPath').value)">Open</button></div><!-- <-- CHANGE 2 -->
<div class="tablewrap"><table><thead><tr><th>Name</th><th>Size</th><th>Modified</th><th>Actions</th></tr></thead><tbody id="fileRows"><tr><td colspan="4" class="empty">Loading…</td></tr></tbody></table></div>
<footer id="itemCount">Loading files…</footer>
</section>
</main>
<div class="modal-bg" id="editorModal"><section class="modal"><div class="modal-head"><strong id="editorTitle">Edit file</strong><button onclick="closeEditor()">Close</button></div><div class="modal-body"><textarea id="editorText" spellcheck="false"></textarea></div><div class="modal-foot"><button onclick="closeEditor()">Cancel</button><button class="primary" onclick="saveEditor()">Save changes</button></div></section></div>
<div class="toast" id="toast"></div>
<script>
let currentDirectory=<?= json_encode($homeDir) ?>, editingPath='';   /* <-- CHANGE 3 */
const el=id=>document.getElementById(id);
function showMessage(message,isError=false){const box=el('toast');box.textContent=message;box.className='toast'+(isError?' error':'');box.style.display='block';clearTimeout(window.messageTimer);window.messageTimer=setTimeout(()=>box.style.display='none',3500)}
async function request(action,values={},files=null){
 const data=new FormData();data.append('path',values.path??currentDirectory);
 Object.entries(values).forEach(([key,value])=>{if(key!=='path')data.append(key,value)});
 if(files)Array.from(files).forEach(file=>data.append('upload_files[]',file));
 const response=await fetch('?action='+encodeURIComponent(action),{method:'POST',body:data});
 if(action==='download'){if(!response.ok){const err=await response.json();throw new Error(err.error||'Download failed')}const blob=await response.blob();const url=URL.createObjectURL(blob);const a=document.createElement('a');a.href=url;a.download=values.path.split('/').pop()||'download';a.click();URL.revokeObjectURL(url);return {ok:true}}
 const result=await response.json();if(!response.ok||!result.ok)throw new Error(result.error||'Operation failed');return result;
}
async function load(path=currentDirectory){try{const result=await request('list',{path});currentDirectory=result.directory;el('currentPath').value=currentDirectory;render(result.entries);el('itemCount').textContent=result.entries.length+' item(s)'}catch(error){showMessage(error.message,true)}}
function render(entries){const body=el('fileRows');body.innerHTML='';if(!entries.length){body.innerHTML='<tr><td colspan="4" class="empty">This folder is empty.</td></tr>';return}
 entries.forEach(entry=>{const row=document.createElement('tr'),nameCell=document.createElement('td'),file=document.createElement('div'),icon=document.createElement('span'),label=document.createElement('span');file.className='file';icon.className='ico '+(entry.type==='folder'?'folder':'');icon.textContent=entry.type==='folder'?'📁':entry.type==='link'?'🔗':'📄';label.className='filename';label.textContent=entry.name;file.append(icon,label);nameCell.append(file);row.append(nameCell);
 [entry.size,entry.modified].forEach(value=>{const cell=document.createElement('td');cell.textContent=value;row.append(cell)});
 const actionCell=document.createElement('td'),actions=document.createElement('div');actions.className='actions';
 const addButton=(text,callback,extra='')=>{const button=document.createElement('button');button.className='small '+extra;button.textContent=text;button.onclick=callback;actions.append(button)};
 if(entry.type==='folder')addButton('Open',()=>go(entry.path));else if(entry.type==='file'){addButton('Edit',()=>editFile(entry.path));addButton('Download',()=>downloadFile(entry.path))}
 addButton('Rename',()=>renameItem(entry.path,entry.name));addButton('Delete',()=>deleteItem(entry.path),'danger');
 actionCell.append(actions);row.append(actionCell);
 row.ondblclick=()=>entry.type==='folder'?go(entry.path):entry.type==='file'?editFile(entry.path):null;
 body.append(row);
 });
}
async function go(path){await load(path)}
async function refresh(){await load(currentDirectory)}
async function goParent(){await load(currentDirectory==='/'?'/':currentDirectory.replace(/\/[^/]+\/?$/,'')||'/')}
async function createItem(action){const kind=action==='create-folder'?'folder':'file';const name=prompt('New '+kind+' name:');if(!name)return;try{const result=await request(action,{name});showMessage(result.message);refresh()}catch(error){showMessage(error.message,true)}}
async function uploadFiles(files){if(!files.length)return;try{const result=await request('upload',{},files);showMessage(result.message);refresh()}catch(error){showMessage(error.message,true)}el('upload').value=''}
async function editFile(path){try{const result=await request('read',{path});editingPath=path;el('editorTitle').textContent='Editing: '+path;el('editorText').value=result.content;el('editorModal').style.display='flex'}catch(error){showMessage(error.message,true)}}
function closeEditor(){el('editorModal').style.display='none'}
async function saveEditor(){try{const result=await request('save',{path:editingPath,content:el('editorText').value});showMessage(result.message);closeEditor()}catch(error){showMessage(error.message,true)}}
async function downloadFile(path){try{await request('download',{path})}catch(error){showMessage(error.message,true)}}
async function renameItem(path,oldName){const name=prompt('New name:',oldName);if(!name||name===oldName)return;try{const result=await request('rename',{path,name});showMessage(result.message);refresh()}catch(error){showMessage(error.message,true)}}
async function deleteItem(path){if(!confirm('Permanently delete '+path+'?'))return;try{const result=await request('delete',{path});showMessage(result.message);refresh()}catch(error){showMessage(error.message,true)}}
load(currentDirectory);   /* <-- CHANGE 4 */
</script>
</body>
</html>