<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>CSV Upload</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light py-5">
<div class="container">

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <form id="upload-form" enctype="multipart/form-data" class="mb-4">
        @csrf
        <input type="hidden" name="upload_started_at" id="upload_started_at">

        <div class="row g-3 align-items-center">
            <div class="col-md-9">
                <div class="border border-2 border-secondary rounded p-4 text-center text-muted"
                    id="drop-zone" style="cursor:pointer;">
                    <span id="drop-text">Select file / Drag and drop</span>
                    <input type="file" id="file-input" name="file" class="d-none" required>
                </div>
            </div>
            <div class="col-md-3">
                <button type="button" class="btn btn-outline-dark w-100" id="upload-btn">
                    Upload File
                </button>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="card-header">Background Process</div>
        <div class="card-body p-0">
            <table class="table table-striped table-hover mb-0 align-middle">
                <thead class="table-secondary">
                    <tr>
                        <th>Time</th>
                        <th>File Name</th>
                        <th>Status</th>
                        <th>Duration</th>
                    </tr>
                </thead>
                <tbody id="bg-tbody">
                    <tr>
                        <td colspan="4" class="text-center text-muted py-3">
                            Belum ada proses upload.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('file-input');
    const dropText = document.getElementById('drop-text');
    const uploadBtn = document.getElementById('upload-btn');
    const form = document.getElementById('upload-form');
    const uploadStartedAtInput = document.getElementById('upload_started_at');
    const bgTbody = document.getElementById('bg-tbody');

    // { tempId: { id, fileName, startedAt, backgroundId:null|int, error?:bool } }
    const uploadingRows = {};

    // === PICK FILE ===
    dropZone.addEventListener('click', () => {
        fileInput.click();
    });

    fileInput.addEventListener('change', () => {
        dropText.textContent = fileInput.files.length
            ? fileInput.files[0].name
            : 'Select file / Drag and drop';
    });

    // Drag & drop
    dropZone.addEventListener('dragover', e => {
        e.preventDefault();
        dropZone.classList.add('border-primary');
    });

    dropZone.addEventListener('dragleave', e => {
        e.preventDefault();
        dropZone.classList.remove('border-primary');
    });

    dropZone.addEventListener('drop', e => {
        e.preventDefault();
        dropZone.classList.remove('border-primary');
        if (e.dataTransfer.files.length) {
            fileInput.files = e.dataTransfer.files;
            dropText.textContent = fileInput.files[0].name;
        }
    });

    function formatDuration(sec) {
        sec = parseInt(sec || 0, 10);
        if (sec <= 0) return '-';
        if (sec < 60) return sec + 's';
        const m = Math.floor(sec / 60);
        const s = sec % 60;
        return m + 'm ' + (s ? s + 's' : '');
    }

    function parseDateTime(value) {
        if (!value) return null;

        if (value.includes('T')) {
            const d = new Date(value);
            return isNaN(d.getTime()) ? null : d;
        }

        const match = value.match(
            /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/
        );
        if (!match) return null;

        const [_, y, m, d, h, i, s] = match.map(Number);
        const dObj = new Date(Date.UTC(y, m - 1, d, h, i, s));

        return isNaN(dObj.getTime()) ? null : dObj;
    }


    // RENDER: gabungkan data resmi + uploadingRows
    function renderTable(rowsFromServerRaw) {
        const rowsFromServer = Array.isArray(rowsFromServerRaw) ? rowsFromServerRaw : [];
        bgTbody.innerHTML = '';

        // index background_id dari server untuk sinkronisasi
        const serverIds = new Set(
            rowsFromServer
                .filter(r => r.id !== undefined && r.id !== null)
                .map(r => r.id)
        );

        // 1) render data resmi dari server
        rowsFromServer.forEach(row => {
            let badge = 'bg-secondary';
            if (row.status === 'processing') badge = 'bg-info text-dark';
            else if (row.status === 'completed') badge = 'bg-success';
            else if (row.status === 'failed') badge = 'bg-danger';

            // HITUNG DURASI DI VIEW (tanpa ubah controller)
            let diffSec = 0;

            if (row.upload_started_at) {
                const startDate = parseDateTime(row.upload_started_at);

                if (startDate) {
                    if (row.finished_at) {
                        const endDate = parseDateTime(row.finished_at) || new Date();
                        diffSec = Math.max(Math.round((endDate.getTime() - startDate.getTime()) / 1000), 0);
                    } else {
                        // belum selesai: hitung sampai sekarang
                        const now = new Date();
                        diffSec = Math.max(Math.round((now.getTime() - startDate.getTime()) / 1000), 0);
                    }
                }
            } else if (typeof row.total_seconds !== 'undefined') {
                diffSec = parseInt(row.total_seconds || 0, 10);
            }

            const duration = formatDuration(diffSec);

            bgTbody.innerHTML += `
                <tr>
                    <td>${row.created_at ?? ''}</td>
                    <td>${row.file_name ?? ''}</td>
                    <td><span class="badge ${badge}">${row.status}</span></td>
                    <td>${duration}</td>
                </tr>
            `;
        });


        // 2) sinkronisasi uploadingRows:
        // - kalau sudah punya backgroundId & sudah muncul di serverIds -> hapus (resmi sudah takeover)
        // - kalau error -> tampilkan sebagai failed
        // - selain itu -> tetap tampil "uploading..."
        Object.keys(uploadingRows).forEach(tempId => {
            const u = uploadingRows[tempId];

            if (u.backgroundId && serverIds.has(u.backgroundId)) {
                delete uploadingRows[tempId];
                return;
            }
            const statusBadge = u.error
                ? '<span class="badge bg-danger">failed</span>'
                : '<span class="badge bg-secondary">uploading...</span>';

            bgTbody.insertAdjacentHTML('afterbegin', `
                <tr id="${u.id}">
                    <td>${u.startedAt}</td>
                    <td>${u.fileName}</td>
                    <td>${statusBadge}</td>
                    <td>-</td>
                </tr>
            `);
        });

        // 3) kalau kosong semua
        if (!rowsFromServer.length && Object.keys(uploadingRows).length === 0) {
            bgTbody.innerHTML = `
                <tr>
                    <td colspan="4" class="text-center text-muted py-3">
                        Belum ada proses upload.
                    </td>
                </tr>
            `;
        }
    }

    function loadBackgroundStatus() {
        fetch("{{ route('upload.background') }}")
            .then(r => r.json())
            .then(rows => {
                renderTable(rows);
            })
            .catch(() => {
                // kalau gagal API, tetap render upload lokal
                renderTable(null);
            });
    }

    setInterval(loadBackgroundStatus, 3000);
    loadBackgroundStatus();

    // === HANDLE UPLOAD  ===
    uploadBtn.addEventListener('click', async () => {
        if (!fileInput.files.length) {
            alert('Pilih file terlebih dahulu.');
            return;
        }

        const file = fileInput.files[0];

        // waktu mulai upload
        const startedAt = new Date().toISOString();
        uploadStartedAtInput.value = startedAt;

        // buat ID unik untuk row lokal
        const tempId = 'uploading-' + Date.now() + '-' + Math.random().toString(16).slice(2);

        // simpan state lokal (belum ada backgroundId)
        uploadingRows[tempId] = {
            id: tempId,
            fileName: file.name,
            startedAt: startedAt,
            backgroundId: null,
            error: false
        };

        // render dengan row baru
        renderTable(null);

        // buat FormData TERPISAH untuk upload ini
        const formData = new FormData();
        formData.append('_token', document.querySelector('input[name=_token]').value);
        formData.append('upload_started_at', startedAt);
        formData.append('file', file);

        try {
            const res = await fetch("{{ route('upload.store') }}", {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!res.ok) {
                uploadingRows[tempId].error = true;
                renderTable(null);
                return;
            }

            const data = await res.json().catch(() => null);

            if (!data || !data.success || !data.background_id) {
                uploadingRows[tempId].error = true;
                renderTable(null);
                return;
            }

            uploadingRows[tempId].backgroundId = data.background_id;
            loadBackgroundStatus();

        } catch (e) {
            console.error(e);
            uploadingRows[tempId].error = true;
            renderTable(null);
        } finally {
            fileInput.value = '';
            dropText.textContent = 'Select file / Drag and drop';
        }
    });
</script>


</body>
</html>
