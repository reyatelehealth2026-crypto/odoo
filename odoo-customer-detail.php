<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายละเอียดลูกค้า - CNY ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        :root {
            --white:#fff;--gray-50:#f9fafb;--gray-100:#f3f4f6;--gray-200:#e5e7eb;--gray-300:#d1d5db;
            --gray-400:#9ca3af;--gray-500:#6b7280;--gray-600:#4b5563;--gray-700:#374151;--gray-800:#1f2937;
            --gray-900:#111827;--black:#000;--success:#22c55e;--danger:#ef4444;--primary:#3b82f6;
        }
        *{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:'IBM Plex Sans Thai',sans-serif;background:var(--gray-100);min-height:100vh;color:var(--gray-800);}
        .top-bar{background:var(--white);border-bottom:1px solid var(--gray-200);padding:0.75rem 1.5rem;position:sticky;top:0;z-index:100;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;align-items:center;gap:1rem;}
        .top-bar a{color:var(--gray-500);text-decoration:none;font-size:0.9rem;display:inline-flex;align-items:center;gap:0.3rem;}
        .top-bar a:hover{color:var(--gray-800);}
        .top-bar .cust-title{font-size:1.1rem;font-weight:600;color:var(--gray-800);margin-left:0.5rem;}
        .main{max-width:1400px;margin:0 auto;padding:1.5rem;}

        /* Profile Header */
        .profile-card{background:var(--white);border:1px solid var(--gray-200);border-radius:14px;padding:1.25rem 1.5rem;margin-bottom:1.25rem;display:flex;gap:1.25rem;align-items:flex-start;flex-wrap:wrap;}
        .profile-avatar{width:64px;height:64px;background:var(--gray-200);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.8rem;color:var(--gray-400);flex-shrink:0;}
        .profile-info{flex:1;min-width:200px;}
        .profile-name{font-size:1.15rem;font-weight:700;margin-bottom:0.15rem;}
        .profile-meta{font-size:0.82rem;color:var(--gray-500);display:flex;flex-wrap:wrap;gap:0.75rem;}
        .profile-meta span{display:inline-flex;align-items:center;gap:0.25rem;}
        .badge-line{background:#06c755;color:#fff;padding:2px 8px;border-radius:50px;font-size:0.72rem;font-weight:500;}
        .badge-no-line{background:var(--gray-100);color:var(--gray-400);padding:2px 8px;border-radius:50px;font-size:0.72rem;}

        /* Summary Cards */
        .summary-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.25rem;}
        .sum-card{background:var(--white);border:1px solid var(--gray-200);border-radius:12px;padding:1rem 1.25rem;}
        .sum-label{font-size:0.78rem;color:var(--gray-500);margin-bottom:0.25rem;}
        .sum-value{font-size:1.2rem;font-weight:700;}
        .sum-value.green{color:#16a34a;} .sum-value.red{color:#dc2626;} .sum-value.blue{color:#1d4ed8;} .sum-value.purple{color:#7c3aed;}

        /* Tabs */
        .tab-bar{display:flex;gap:0;border-bottom:2px solid var(--gray-200);margin-bottom:1.25rem;overflow-x:auto;}
        .tab-btn{padding:0.6rem 1.25rem;border:none;border-bottom:3px solid transparent;background:none;cursor:pointer;font-size:0.88rem;font-weight:500;color:var(--gray-500);white-space:nowrap;transition:all 0.15s;}
        .tab-btn:hover{color:var(--gray-700);}
        .tab-btn.active{color:var(--primary);border-bottom-color:var(--primary);font-weight:600;}
        .tab-panel{display:none;}
        .tab-panel.active{display:block;animation:fadeIn 0.25s ease;}

        /* Content card */
        .card{background:var(--white);border:1px solid var(--gray-200);border-radius:14px;padding:1.25rem;margin-bottom:1rem;}
        .card-title{font-size:0.95rem;font-weight:600;margin-bottom:0.75rem;display:flex;align-items:center;gap:0.5rem;}

        /* Tables */
        .tbl{width:100%;border-collapse:collapse;font-size:0.84rem;}
        .tbl th{background:var(--gray-50);padding:0.5rem 0.6rem;text-align:left;border-bottom:2px solid var(--gray-200);font-weight:600;white-space:nowrap;}
        .tbl td{padding:0.5rem 0.6rem;border-bottom:1px solid var(--gray-100);vertical-align:top;}
        .tbl tr:hover{background:var(--gray-50);}

        /* Badges */
        .st{padding:2px 8px;border-radius:50px;font-size:0.73rem;font-weight:500;white-space:nowrap;}
        .st-paid{background:#dbeafe;color:#1d4ed8;} .st-open{background:#fef9c3;color:#854d0e;}
        .st-overdue{background:#fee2e2;color:#dc2626;} .st-cancel{background:#f1f5f9;color:#64748b;}
        .st-delivered{background:#dbeafe;color:#1d4ed8;} .st-draft{background:#fef9c3;color:#854d0e;}
        .st-posted{background:#dcfce7;color:#16a34a;} .st-sale{background:#dcfce7;color:#16a34a;}
        .st-done{background:#dbeafe;color:#1d4ed8;} .st-confirmed{background:#e0f2fe;color:#0369a1;}
        .st-to_delivery{background:#f3e8ff;color:#7c3aed;} .st-packed{background:#cffafe;color:#0891b2;}
        .st-override{background:#fef3c7;color:#92400e;border:1px dashed #f59e0b;}

        /* Action buttons */
        .btn-sm{padding:3px 10px;border:none;border-radius:6px;font-size:0.75rem;cursor:pointer;display:inline-flex;align-items:center;gap:3px;font-weight:500;}
        .btn-blue{background:var(--primary);color:#fff;} .btn-blue:hover{background:#2563eb;}
        .btn-green{background:#16a34a;color:#fff;} .btn-green:hover{background:#15803d;}
        .btn-amber{background:#f59e0b;color:#fff;} .btn-amber:hover{background:#d97706;}
        .btn-red{background:#dc2626;color:#fff;} .btn-red:hover{background:#b91c1c;}
        .btn-gray{background:var(--gray-200);color:var(--gray-700);} .btn-gray:hover{background:var(--gray-300);}

        /* Modal */
        .modal-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;padding:1rem;}
        .modal-overlay.show{display:flex;}
        .modal-box{background:#fff;border-radius:16px;max-width:500px;width:100%;padding:1.5rem;position:relative;max-height:90vh;overflow-y:auto;}
        .modal-box h5{font-size:1rem;font-weight:600;margin-bottom:1rem;}
        .modal-box label{display:block;font-size:0.82rem;color:var(--gray-600);font-weight:500;margin-bottom:0.3rem;}
        .modal-box input,.modal-box select,.modal-box textarea{width:100%;border:1px solid var(--gray-300);border-radius:8px;padding:0.5rem 0.75rem;font-size:0.88rem;margin-bottom:0.75rem;font-family:inherit;}
        .modal-box textarea{min-height:80px;resize:vertical;}
        .modal-close{position:absolute;top:0.75rem;right:0.75rem;background:none;border:none;font-size:1.3rem;cursor:pointer;color:var(--gray-400);}

        /* Notes inline */
        .note-item{background:var(--gray-50);border-left:3px solid var(--primary);padding:0.5rem 0.75rem;margin-top:0.4rem;border-radius:0 6px 6px 0;font-size:0.78rem;}
        .note-item .note-meta{font-size:0.72rem;color:var(--gray-400);margin-top:0.15rem;}
        .override-item{background:#fef3c7;border-left:3px solid #f59e0b;padding:0.5rem 0.75rem;margin-top:0.4rem;border-radius:0 6px 6px 0;font-size:0.78rem;}

        /* Loading / empty */
        .loading{text-align:center;padding:2rem;color:var(--gray-500);}
        .spin{animation:spin 1s linear infinite;display:inline-block;}
        @keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}
        @keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}

        /* Slip preview */
        .slip-thumb{width:36px;height:44px;object-fit:cover;border-radius:5px;cursor:pointer;border:1px solid #d1fae5;}
        .slip-thumb:hover{box-shadow:0 2px 8px rgba(0,0,0,0.15);}

        /* Profile detail grid */
        .info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem;}
        .info-box{background:var(--gray-50);padding:0.75rem 1rem;border-radius:8px;}
        .info-box .lbl{font-size:0.75rem;color:var(--gray-500);margin-bottom:0.15rem;}
        .info-box .val{font-size:0.92rem;font-weight:600;}

        @media(max-width:768px){
            .main{padding:0.75rem;}
            .profile-card{flex-direction:column;}
            .summary-row{grid-template-columns:repeat(2,1fr);}
        }
    </style>
</head>
<body>

<!-- Top Bar -->
<div class="top-bar">
    <a href="odoo-dashboard.php"><i class="bi bi-arrow-left"></i> กลับรายการลูกค้า</a>
    <span class="cust-title" id="topTitle">รายละเอียดลูกค้า</span>
</div>

<div class="main">
    <!-- Profile Header -->
    <div class="profile-card" id="profileCard">
        <div class="loading"><i class="bi bi-arrow-repeat spin"></i> กำลังโหลด...</div>
    </div>

    <!-- Summary Cards -->
    <div class="summary-row" id="summaryRow">
        <div class="sum-card"><div class="sum-label">ยอดรวม</div><div class="sum-value" id="sumTotal">-</div></div>
        <div class="sum-card"><div class="sum-label">ค้างชำระ</div><div class="sum-value red" id="sumDue">-</div></div>
        <div class="sum-card"><div class="sum-label">เครดิตคงเหลือ</div><div class="sum-value blue" id="sumCredit">-</div></div>
        <div class="sum-card"><div class="sum-label">คะแนนสะสม</div><div class="sum-value purple" id="sumPoints">-</div></div>
    </div>

    <!-- Tabs -->
    <div class="tab-bar" id="tabBar">
        <button class="tab-btn active" onclick="switchTab('orders')"><i class="bi bi-bag"></i> ออเดอร์ <span id="tabCountOrders"></span></button>
        <button class="tab-btn" onclick="switchTab('invoices')"><i class="bi bi-file-text"></i> ใบแจ้งหนี้ <span id="tabCountInvoices"></span></button>
        <button class="tab-btn" onclick="switchTab('bdos')"><i class="bi bi-truck"></i> BDO <span id="tabCountBdos"></span></button>
        <button class="tab-btn" onclick="switchTab('slips')"><i class="bi bi-receipt"></i> สลิป <span id="tabCountSlips"></span></button>
        <button class="tab-btn" onclick="switchTab('profile')"><i class="bi bi-person-vcard"></i> โปรไฟล์ Odoo</button>
        <button class="tab-btn" onclick="switchTab('timeline')"><i class="bi bi-clock-history"></i> Timeline</button>
        <button class="tab-btn" onclick="switchTab('activity')"><i class="bi bi-journal-text"></i> Activity Log</button>
    </div>

    <!-- Tab Panels -->
    <div class="tab-panel active" id="panel-orders">
        <div class="card"><div id="ordersContent"><div class="loading"><i class="bi bi-arrow-repeat spin"></i> กำลังโหลด...</div></div></div>
    </div>
    <div class="tab-panel" id="panel-invoices">
        <div class="card"><div id="invoicesContent"><div class="loading"><i class="bi bi-arrow-repeat spin"></i> กำลังโหลด...</div></div></div>
    </div>
    <div class="tab-panel" id="panel-bdos">
        <div class="card"><div id="bdosContent"><div class="loading"><i class="bi bi-arrow-repeat spin"></i> กำลังโหลด...</div></div></div>
    </div>
    <div class="tab-panel" id="panel-slips">
        <div class="card"><div id="slipsContent"><div class="loading"><i class="bi bi-arrow-repeat spin"></i> กำลังโหลด...</div></div></div>
    </div>
    <div class="tab-panel" id="panel-profile">
        <div class="card"><div id="profileContent"><div class="loading"><i class="bi bi-arrow-repeat spin"></i> กำลังโหลด...</div></div></div>
    </div>
    <div class="tab-panel" id="panel-timeline">
        <div class="card"><div id="timelineContent"><div class="loading"><i class="bi bi-arrow-repeat spin"></i> กำลังโหลด...</div></div></div>
    </div>
    <div class="tab-panel" id="panel-activity">
        <div class="card"><div id="activityContent"><div class="loading"><i class="bi bi-arrow-repeat spin"></i> กำลังโหลด...</div></div></div>
    </div>
</div>

<!-- Override Status Modal -->
<div class="modal-overlay" id="overrideModal">
    <div class="modal-box">
        <button class="modal-close" onclick="closeModal('overrideModal')">&times;</button>
        <h5><i class="bi bi-pencil-square"></i> แก้ไขสถานะ</h5>
        <input type="hidden" id="ovEntityType"><input type="hidden" id="ovEntityRef"><input type="hidden" id="ovOldStatus">
        <div style="background:var(--gray-50);padding:0.6rem 0.8rem;border-radius:8px;margin-bottom:0.75rem;font-size:0.85rem;">
            <strong id="ovRefDisplay"></strong> — สถานะปัจจุบัน: <span id="ovOldDisplay" class="st"></span>
        </div>
        <label>สถานะใหม่ *</label>
        <select id="ovNewStatus"></select>
        <label>เหตุผลในการแก้ไข *</label>
        <textarea id="ovReason" placeholder="ระบุเหตุผล..."></textarea>
        <label>ชื่อผู้ดำเนินการ *</label>
        <input type="text" id="ovAdminName" placeholder="ชื่อแอดมิน">
        <div style="display:flex;gap:0.5rem;justify-content:flex-end;margin-top:0.5rem;">
            <button class="btn-sm btn-gray" onclick="closeModal('overrideModal')">ยกเลิก</button>
            <button class="btn-sm btn-amber" id="ovSubmitBtn" onclick="submitOverride()"><i class="bi bi-check-lg"></i> บันทึก</button>
        </div>
    </div>
</div>

<!-- Add Note Modal -->
<div class="modal-overlay" id="noteModal">
    <div class="modal-box">
        <button class="modal-close" onclick="closeModal('noteModal')">&times;</button>
        <h5><i class="bi bi-chat-left-text"></i> เพิ่มโน้ต / หมายเหตุ</h5>
        <input type="hidden" id="ntEntityType"><input type="hidden" id="ntEntityRef">
        <div style="background:var(--gray-50);padding:0.6rem 0.8rem;border-radius:8px;margin-bottom:0.75rem;font-size:0.85rem;">
            <strong id="ntRefDisplay"></strong>
        </div>
        <label>โน้ต *</label>
        <textarea id="ntNote" placeholder="เขียนหมายเหตุ..."></textarea>
        <label>ชื่อผู้ดำเนินการ *</label>
        <input type="text" id="ntAdminName" placeholder="ชื่อแอดมิน">
        <div style="display:flex;gap:0.5rem;justify-content:flex-end;margin-top:0.5rem;">
            <button class="btn-sm btn-gray" onclick="closeModal('noteModal')">ยกเลิก</button>
            <button class="btn-sm btn-blue" id="ntSubmitBtn" onclick="submitNote()"><i class="bi bi-check-lg"></i> บันทึก</button>
        </div>
    </div>
</div>

<!-- Slip Preview Modal -->
<div class="modal-overlay" id="slipPreviewModal" onclick="closeModal('slipPreviewModal')">
    <div style="max-width:600px;margin:auto;" onclick="event.stopPropagation()">
        <img id="slipPreviewImg" src="" style="width:100%;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,0.3);">
    </div>
</div>

<!-- BDO Slip Attach Modal -->
<div class="modal-overlay" id="bdoSlipAttachModal">
    <div class="modal-box" style="max-width:520px;" onclick="event.stopPropagation()">
        <button class="modal-close" onclick="closeModal('bdoSlipAttachModal')">&times;</button>
        <h5><i class="bi bi-paperclip" style="color:#059669;"></i> แนบสลิปให้ BDO</h5>
        <div style="background:var(--gray-50);padding:0.6rem 0.8rem;border-radius:8px;margin-bottom:0.75rem;display:flex;gap:16px;flex-wrap:wrap;">
            <div><span style="font-size:0.72rem;color:var(--gray-500);">BDO</span><div style="font-weight:600;" id="bsaBdoName">-</div></div>
            <div><span style="font-size:0.72rem;color:var(--gray-500);">ออเดอร์</span><div style="color:#1d4ed8;" id="bsaOrderName">-</div></div>
            <div><span style="font-size:0.72rem;color:var(--gray-500);">ยอดเงิน</span><div style="font-weight:700;color:#059669;" id="bsaAmount">-</div></div>
        </div>
        <div style="font-weight:600;font-size:0.85rem;margin-bottom:6px;"><i class="bi bi-images"></i> สลิปที่ยังไม่ได้จับคู่ (คลิกเลือก)</div>
        <div id="bsaUnmatchedSlips" style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:10px;">
            <span style="color:var(--gray-400);font-size:0.8rem;grid-column:1/-1;text-align:center;padding:1rem;">ไม่มีสลิปที่รอจับคู่</span>
        </div>
        <div style="text-align:center;margin-bottom:10px;">
            <label style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:1.5px dashed var(--gray-300);border-radius:8px;cursor:pointer;font-size:0.82rem;color:var(--gray-600);">
                <i class="bi bi-upload"></i> อัพโหลดรูปใหม่
                <input type="file" accept="image/*" id="bsaFileInput" onchange="bsaHandleFileSelect(event)" style="display:none;">
            </label>
        </div>
        <div id="bsaPreview" style="display:none;text-align:center;margin-bottom:10px;">
            <img id="bsaPreviewImg" style="max-width:200px;max-height:150px;border-radius:8px;border:2px solid #059669;">
            <button onclick="bsaClearPreview()" style="display:block;margin:4px auto;font-size:0.75rem;color:var(--gray-400);background:none;border:none;cursor:pointer;">✕ ลบรูป</button>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div><label>จำนวนเงิน (บาท)</label><input type="number" step="0.01" min="0" id="bsaAmountInput" placeholder="auto-fill"></div>
            <div><label>วันที่โอน</label><input type="date" id="bsaDateInput"></div>
        </div>
        <div style="display:flex;gap:0.5rem;justify-content:flex-end;margin-top:0.75rem;">
            <button class="btn-sm btn-gray" onclick="closeModal('bdoSlipAttachModal')">ยกเลิก</button>
            <button class="btn-sm btn-green" id="bsaConfirmBtn" onclick="bsaConfirmAttach()" disabled><i class="bi bi-check-lg"></i> แนบสลิป</button>
        </div>
    </div>
</div>

<script src="odoo-customer-detail.js?v=<?= filemtime(__DIR__ . '/odoo-customer-detail.js') ?>"></script>
</body>
</html>
