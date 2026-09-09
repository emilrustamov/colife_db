<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cleaning Report Form</title>
    <script src="//api.bitrix24.com/api/v1/"></script>
    <style>
        :root {
            --blue: #2b4e9b;
            --blue-mid: #3a63b8;
            --blue-soft: #eaf1fb;
            --blue-tint: #f3f7fd;
            --border: #c9d6ea;
            --border-soft: #d9e3f2;
            --text: #1f2f52;
            --muted: #7a89a3;
            --bg: #eef2f7;
            --white: #ffffff;
            --danger: #dc2626;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: var(--bg);
            color: var(--text);
        }
        .wrap {
            max-width: 880px;
            margin: 0 auto;
            padding: 18px 14px 32px;
        }
        .card {
            background: var(--white);
            border-radius: 18px;
            padding: 28px 30px 24px;
            box-shadow: 0 12px 32px rgba(43, 78, 155, 0.10);
        }
        .header {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-start;
            margin-bottom: 16px;
        }
        .title-row {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .title-badge {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: var(--blue);
            display: grid;
            place-items: center;
            flex-shrink: 0;
        }
        .title-badge svg { width: 22px; height: 22px; fill: none; stroke: #fff; stroke-width: 1.8; }
        .header h1 {
            margin: 0;
            font-size: 26px;
            line-height: 1.15;
            color: var(--blue);
            font-weight: 700;
        }
        .header p {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 14px;
        }
        .logo {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 700;
            letter-spacing: 0.03em;
            color: var(--blue);
            white-space: nowrap;
            font-size: 14px;
        }
        .logo svg { width: 28px; height: 28px; }
        .banner {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            background: var(--blue-soft);
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 22px;
        }
        .banner-item {
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }
        .banner-item .ico {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            border: 1.5px solid var(--blue);
            background: transparent;
            display: grid;
            place-items: center;
            flex-shrink: 0;
        }
        .banner-item .ico svg {
            width: 16px;
            height: 16px;
            stroke: var(--blue);
            fill: none;
            stroke-width: 1.7;
        }
        .banner-item strong {
            display: block;
            font-size: 13px;
            margin-bottom: 2px;
            color: var(--text);
        }
        .banner-item span {
            font-size: 11px;
            color: var(--muted);
            line-height: 1.3;
        }
        .field { margin-bottom: 18px; }
        .label {
            display: block;
            font-weight: 700;
            margin-bottom: 8px;
            font-size: 14px;
            color: var(--text);
        }
        .label .num { color: var(--blue); margin-right: 2px; }
        .row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .control { position: relative; }
        .control select,
        .control input[type="text"],
        .control input[type="date"],
        .control input[type="time"],
        .control textarea {
            width: 100%;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            padding: 12px 36px 12px 42px;
            font-size: 14px;
            background: #fff;
            color: var(--text);
            outline: none;
            appearance: none;
            -webkit-appearance: none;
        }
        .control select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='none' stroke='%232b4e9b' stroke-width='1.6' d='M1 1.5 6 6.5 11 1.5'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
        }
        .control textarea {
            min-height: 100px;
            resize: vertical;
            padding-left: 42px;
            padding-right: 14px;
        }
        .control .icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            display: grid;
            place-items: center;
        }
        .control .icon svg {
            width: 18px;
            height: 18px;
            stroke: var(--blue);
            fill: none;
            stroke-width: 1.7;
        }
        .control.combobox { z-index: 5; }
        .combobox-list {
            display: none;
            position: absolute;
            left: 0;
            right: 0;
            top: calc(100% + 4px);
            max-height: 220px;
            overflow-y: auto;
            background: #fff;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            box-shadow: 0 10px 24px rgba(43, 78, 155, 0.12);
            z-index: 20;
        }
        .combobox-list.open { display: block; }
        .combobox-list button {
            display: block;
            width: 100%;
            text-align: left;
            border: none;
            background: transparent;
            padding: 10px 14px 10px 42px;
            font-size: 14px;
            color: var(--text);
            cursor: pointer;
        }
        .combobox-list button:hover,
        .combobox-list button.active {
            background: var(--blue-soft);
        }
        .combobox-list .empty {
            padding: 10px 14px;
            font-size: 13px;
            color: var(--muted);
        }
        .type-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }
        .type-card {
            border: 1.5px solid var(--border);
            border-radius: 12px;
            padding: 18px 12px;
            text-align: center;
            cursor: pointer;
            background: #fff;
            transition: border-color .15s, background .15s, box-shadow .15s;
            user-select: none;
        }
        .type-card.active {
            border-color: var(--blue);
            background: var(--blue-tint);
            box-shadow: 0 0 0 1px var(--blue);
        }
        .type-card .ico {
            margin-bottom: 8px;
            display: flex;
            justify-content: center;
        }
        .type-card .ico svg {
            width: 26px;
            height: 26px;
            stroke: var(--blue);
            fill: none;
            stroke-width: 1.6;
        }
        .type-card .name { font-weight: 700; font-size: 14px; color: var(--text); }
        .checklist {
            background: var(--blue-tint);
            border: 1.5px solid var(--border-soft);
            border-radius: 12px;
            padding: 16px 18px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px 28px;
        }
        .check-item {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            color: var(--text);
        }
        .check-item input,
        .check-other input[type="checkbox"],
        .repair-box input {
            width: 16px;
            height: 16px;
            accent-color: var(--blue);
            flex-shrink: 0;
        }
        .check-other {
            display: flex;
            align-items: center;
            gap: 8px;
            grid-column: 2;
            font-size: 14px;
        }
        .check-other input[type="text"] {
            flex: 1;
            border: none;
            border-bottom: 1.5px solid #9bb0d4;
            background: transparent;
            padding: 2px 0;
            outline: none;
            min-width: 0;
            color: var(--text);
        }
        .repair-box {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            background: #fff;
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
            cursor: pointer;
        }
        .photos-head {
            font-size: 13px;
            color: var(--muted);
            margin: 0 0 10px;
            font-weight: 500;
        }
        .photos {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .photo-add, .photo-slot, .photo-preview {
            width: 96px;
            height: 96px;
            border-radius: 12px;
            border: 1.5px dashed #aebfd8;
            display: grid;
            place-items: center;
            color: var(--blue);
            background: #f7f9fc;
            position: relative;
            overflow: hidden;
        }
        .photo-add {
            cursor: pointer;
            flex-direction: column;
            gap: 4px;
            font-size: 12px;
            font-weight: 700;
            background: #eef3fa;
        }
        .photo-add .plus { font-size: 28px; line-height: 1; font-weight: 400; }
        .photo-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .photo-preview button {
            position: absolute;
            top: 4px;
            right: 4px;
            border: none;
            background: rgba(0,0,0,.55);
            color: #fff;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            cursor: pointer;
            line-height: 1;
        }
        .actions {
            display: grid;
            grid-template-columns: 1fr 1.35fr;
            gap: 14px;
            margin-top: 24px;
        }
        .btn {
            border-radius: 10px;
            padding: 13px 18px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: 1.5px solid var(--blue);
        }
        .btn svg { width: 16px; height: 16px; }
        .btn-cancel { background: #fff; color: var(--blue); }
        .btn-cancel svg { stroke: var(--blue); fill: none; stroke-width: 2; }
        .btn-submit { background: var(--blue); color: #fff; border-color: var(--blue); }
        .btn-submit svg { stroke: #fff; fill: none; stroke-width: 1.8; }
        .btn-submit:disabled { opacity: .6; cursor: wait; }
        .toast-wrap {
            position: fixed;
            top: 16px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 100;
            display: flex;
            flex-direction: column;
            gap: 8px;
            width: min(420px, calc(100% - 24px));
            pointer-events: none;
        }
        .toast {
            pointer-events: auto;
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 14px;
            font-weight: 600;
            box-shadow: 0 12px 28px rgba(31, 47, 82, 0.18);
            opacity: 0;
            transform: translateY(-8px);
            transition: opacity .2s ease, transform .2s ease;
        }
        .toast.show {
            opacity: 1;
            transform: translateY(0);
        }
        .toast.ok {
            background: #067647;
            color: #fff;
        }
        .toast.err {
            background: #b42318;
            color: #fff;
        }
        .loading-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(255,255,255,.55);
            z-index: 20;
        }
        .loading-overlay.show { display: block; }
        @media (max-width: 760px) {
            .banner { grid-template-columns: 1fr 1fr; }
            .type-grid, .row-2, .checklist, .actions { grid-template-columns: 1fr; }
            .check-other { grid-column: 1; }
            .header { flex-direction: column; }
            .card { padding: 18px 14px; }
        }
    </style>
</head>
<body>
<div class="loading-overlay" id="loading"></div>
<div class="wrap">
    <div class="card">
        <div class="header">
            <div>
                <div class="title-row">
                    <div class="title-badge" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M4 20h4l10-10a2.1 2.1 0 0 0-3-3L5 17v3z"/><path d="M13 6l3 3"/><path d="M16 3l2 1 1 2"/></svg>
                    </div>
                    <div>
                        <h1>Cleaning Report Form</h1>
                        <p>Please fill in the form after each cleaning task.</p>
                    </div>
                </div>
            </div>
            <div class="logo">
                <svg viewBox="0 0 32 32" aria-hidden="true">
                    <path d="M6 14 16 6l10 8v12H6V14z" fill="none" stroke="#2b4e9b" stroke-width="1.8"/>
                    <path d="M13 26v-8h6v8" fill="none" stroke="#2b4e9b" stroke-width="1.8"/>
                    <circle cx="22" cy="8" r="2.2" fill="none" stroke="#2b4e9b" stroke-width="1.5"/>
                </svg>
                <div>COLIFE LIVING</div>
            </div>
        </div>

        <div class="banner">
            <div class="banner-item">
                <div class="ico"><svg viewBox="0 0 24 24"><path d="M4 20V10l8-6 8 6v10H4z"/><path d="M10 20v-6h4v6"/></svg></div>
                <div><strong>Keep it Clean</strong><span>Clean spaces, happy places.</span></div>
            </div>
            <div class="banner-item">
                <div class="ico"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/><path d="M9 15l2 2 4-4"/></svg></div>
                <div><strong>Be Consistent</strong><span>Regular cleaning makes a difference.</span></div>
            </div>
            <div class="banner-item">
                <div class="ico"><svg viewBox="0 0 24 24"><path d="M4 8h4l2-3h4l2 3h4v11H4V8z"/><circle cx="12" cy="13" r="3.5"/></svg></div>
                <div><strong>Take Photos</strong><span>Photos help us stay transparent.</span></div>
            </div>
            <div class="banner-item">
                <div class="ico"><svg viewBox="0 0 24 24"><path d="M12 3 4 7v5c0 5 3.4 8.4 8 9 4.6-.6 8-4 8-9V7l-8-4z"/><path d="M9 12l2 2 4-4"/></svg></div>
                <div><strong>Do It Right</strong><span>Quality work you can be proud of.</span></div>
            </div>
        </div>

        <form id="cleaningForm" novalidate>
            <div class="field">
                <div class="row-2">
                    <div>
                        <label class="label"><span class="num">1.</span> Apartment Number</label>
                        <div class="control combobox">
                            <span class="icon"><svg viewBox="0 0 24 24"><path d="M4 20V9l8-5 8 5v11H4z"/><path d="M9 20v-6h6v6"/></svg></span>
                            <input type="text" id="apartmentSearch" placeholder="Search apartment" autocomplete="off" required>
                            <input type="hidden" id="apartmentId" value="">
                            <div class="combobox-list" id="apartmentList"></div>
                        </div>
                    </div>
                    <div>
                        <label class="label"><span class="num">2.</span> Room / Area</label>
                        <div class="control">
                            <span class="icon"><svg viewBox="0 0 24 24"><rect x="5" y="3" width="14" height="18" rx="2"/><circle cx="15" cy="12" r="1"/></svg></span>
                            <select id="unit" required disabled>
                                <option value="">Select room / area</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="field">
                <label class="label"><span class="num">3.</span> Cleaning Type</label>
                <div class="type-grid" id="cleaningTypes">
                    <div class="type-card active" data-value="Deep Cleaning">
                        <div class="ico"><svg viewBox="0 0 24 24"><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1"/><circle cx="12" cy="12" r="3"/></svg></div>
                        <div class="name">Deep Cleaning</div>
                    </div>
                    <div class="type-card" data-value="Weekly Cleaning">
                        <div class="ico"><svg viewBox="0 0 24 24"><path d="M7 10h10l-1 8H8l-1-8z"/><path d="M9 10V7a3 3 0 0 1 6 0v3"/><path d="M5 10h14"/></svg></div>
                        <div class="name">Weekly Cleaning</div>
                    </div>
                    <div class="type-card" data-value="Other">
                        <div class="ico"><svg viewBox="0 0 24 24"><path d="M8 14c0 4 2 7 4 7s4-3 4-7V8H8v6z"/><path d="M10 8V5h4v3"/><path d="M7 11h10"/></svg></div>
                        <div class="name">Other</div>
                    </div>
                </div>
                <input type="hidden" id="cleaningType" value="Deep Cleaning">
            </div>

            <div class="field">
                <label class="label"><span class="num">4.</span> Date &amp; Time</label>
                <div class="row-2">
                    <div class="control">
                        <span class="icon"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg></span>
                        <input type="date" id="date" required>
                    </div>
                    <div class="control">
                        <span class="icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/><path d="M12 8v5l3 2"/></svg></span>
                        <select id="time" required>
                            <option value="">Select time</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="field">
                <label class="label"><span class="num">5.</span> Cleaning Staff</label>
                <div class="control">
                    <span class="icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"/><path d="M5 19c1.8-3.2 4.2-4.8 7-4.8S17.2 15.8 19 19"/></svg></span>
                    <select id="staff" required>
                        <option value="">Select your name</option>
                        @foreach ($staff as $name)
                            <option value="{{ $name }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="field">
                <label class="label"><span class="num">6.</span> Cleaning Checklist</label>
                <div class="checklist">
                    @foreach (['Kitchen', 'Bathroom', 'Floor', 'Dusting'] as $item)
                        <label class="check-item"><input type="checkbox" name="checklist" value="{{ $item }}"> {{ $item }}</label>
                    @endforeach
                    @foreach (['Windows', 'Trash Removed', 'Surfaces'] as $item)
                        <label class="check-item"><input type="checkbox" name="checklist" value="{{ $item }}"> {{ $item }}</label>
                    @endforeach
                    <label class="check-other">
                        <input type="checkbox" id="checklistOtherToggle" value="Other">
                        Other (please specify)
                        <input type="text" id="checklistOther" placeholder="">
                    </label>
                </div>
            </div>

            <div class="field">
                <label class="label"><span class="num">7.</span> Needs repair</label>
                <label class="repair-box">
                    <input type="checkbox" id="needsRepair">
                    Needs repair
                </label>
            </div>

            <div class="field">
                <label class="label"><span class="num">8.</span> Notes / Additional Info</label>
                <div class="control textarea">
                    <span class="icon"><svg viewBox="0 0 24 24"><path d="M7 3h8l4 4v14H7V3z"/><path d="M15 3v4h4M9 12h8M9 16h8M9 8h3"/></svg></span>
                    <textarea id="notes" placeholder="Write any additional notes here..."></textarea>
                </div>
            </div>

            <div class="field">
                <label class="label"><span class="num">9.</span> Photos</label>
                <p class="photos-head">Add photos of the cleaned area</p>
                <div class="photos" id="photos">
                    <div class="photo-add" id="photoAdd">
                        <div class="plus">+</div>
                        <div>Add Photo</div>
                    </div>
                    <div class="photo-slot"></div>
                    <div class="photo-slot"></div>
                    <div class="photo-slot"></div>
                    <div class="photo-slot"></div>
                </div>
                <input type="file" id="photoInput" accept="image/*" multiple hidden>
            </div>

            <div class="actions">
                <button type="button" class="btn btn-cancel" id="cancelBtn">
                    <svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
                    Cancel
                </button>
                <button type="submit" class="btn btn-submit" id="submitBtn">
                    <svg viewBox="0 0 24 24"><path d="M4 12 20 4l-6 16-2-6-6-2z"/></svg>
                    Submit Report
                </button>
            </div>
        </form>
    </div>
</div>
<div class="toast-wrap" id="toastWrap" aria-live="polite"></div>

<script>
(function () {
    const CONFIG = {
        submitUrl: @json($submitUrl),
        apartmentEntityTypeId: {{ (int) $apartmentEntityTypeId }},
        unitEntityTypeId: {{ (int) $unitEntityTypeId }},
        apartmentUnitsField: @json($apartmentUnitsField),
    };

    const apartmentSearch = document.getElementById('apartmentSearch');
    const apartmentIdInput = document.getElementById('apartmentId');
    const apartmentList = document.getElementById('apartmentList');
    const unitSelect = document.getElementById('unit');
    const cleaningTypeInput = document.getElementById('cleaningType');
    const dateInput = document.getElementById('date');
    const timeInput = document.getElementById('time');
    const staffSelect = document.getElementById('staff');
    const notesInput = document.getElementById('notes');
    const needsRepairInput = document.getElementById('needsRepair');
    const photoInput = document.getElementById('photoInput');
    const photosWrap = document.getElementById('photos');
    const photoAdd = document.getElementById('photoAdd');
    const toastWrap = document.getElementById('toastWrap');
    const loadingEl = document.getElementById('loading');
    const submitBtn = document.getElementById('submitBtn');

    /** @type {File[]} */
    let selectedFiles = [];
    /** @type {Record<string, {id:number,title:string,unitIds:number[]}>} */
    let apartmentsById = {};
    /** @type {Array<{id:number,title:string,unitIds:number[]}>} */
    let apartmentOptions = [];
    let apartmentHighlight = -1;

    function showToast(type, message) {
        const toast = document.createElement('div');
        toast.className = 'toast ' + type;
        toast.textContent = message;
        toastWrap.appendChild(toast);
        requestAnimationFrame(function () {
            toast.classList.add('show');
        });
        setTimeout(function () {
            toast.classList.remove('show');
            setTimeout(function () {
                toast.remove();
            }, 200);
        }, type === 'ok' ? 3500 : 5000);
    }

    function setStatus(type, message) {
        if (type === 'ok') {
            showToast('ok', message);
            return;
        }
        showToast('err', message);
    }

    function clearStatus() {
        // toasts are ephemeral
    }

    function callMethod(method, params) {
        return new Promise(function (resolve, reject) {
            BX24.callMethod(method, params, function (result) {
                if (result.error()) {
                    reject(new Error(result.error()));
                    return;
                }
                resolve(result.data());
            });
        });
    }

    async function listAllItems(entityTypeId, filter, select) {
        const items = [];
        let start = 0;
        while (true) {
            const data = await callMethod('crm.item.list', {
                entityTypeId: entityTypeId,
                filter: filter,
                select: select,
                start: start,
            });
            const batch = (data && data.items) ? data.items : [];
            items.push.apply(items, batch);
            if (typeof data.next === 'undefined' || data.next === null) {
                break;
            }
            start = data.next;
        }
        return items;
    }

    function isActiveStage(item) {
        const semantic = String(item.stageSemanticId || item.stageSemanticID || '').toUpperCase();
        if (semantic === 'F') {
            return false;
        }
        const stageId = String(item.stageId || item.STAGE_ID || '');
        return !/:FAIL$/i.test(stageId);
    }

    function extractUnitIds(raw) {
        if (raw == null || raw === '') {
            return [];
        }
        if (Array.isArray(raw)) {
            return raw.map(function (v) { return parseInt(v, 10); }).filter(function (n) { return n > 0; });
        }
        if (typeof raw === 'object') {
            return Object.values(raw).map(function (v) { return parseInt(v, 10); }).filter(function (n) { return n > 0; });
        }
        return String(raw).split(/[,\s]+/).map(function (v) { return parseInt(v, 10); }).filter(function (n) { return n > 0; });
    }

    async function loadApartments() {
        const field = CONFIG.apartmentUnitsField;
        const items = await listAllItems(
            CONFIG.apartmentEntityTypeId,
            {},
            ['id', 'title', 'stageId', 'stageSemanticId', field, 'UF_CRM_5_1697012153']
        );
        apartmentsById = {};
        apartmentOptions = [];
        items.filter(isActiveStage).sort(function (a, b) {
            return String(a.title || '').localeCompare(String(b.title || ''), undefined, {
                numeric: true,
                sensitivity: 'base',
            });
        }).forEach(function (item) {
            const id = parseInt(item.id, 10);
            if (!id) return;
            const unitIds = extractUnitIds(
                item[field] ?? item.UF_CRM_5_1697012153 ?? item.ufCrm5_1697012153
            );
            const row = {
                id: id,
                title: String(item.title || ('#' + id)),
                unitIds: unitIds,
            };
            apartmentsById[id] = row;
            apartmentOptions.push(row);
        });
        clearApartmentSelection(false);
    }

    function filteredApartments(query) {
        const q = String(query || '').trim().toLowerCase();
        if (!q) {
            return apartmentOptions.slice(0, 50);
        }
        return apartmentOptions.filter(function (item) {
            return item.title.toLowerCase().indexOf(q) !== -1;
        }).slice(0, 50);
    }

    function renderApartmentList(query) {
        const items = filteredApartments(query);
        apartmentList.innerHTML = '';
        apartmentHighlight = -1;
        if (items.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'empty';
            empty.textContent = 'No apartments found';
            apartmentList.appendChild(empty);
            apartmentList.classList.add('open');
            return;
        }
        items.forEach(function (item, index) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = item.title;
            btn.dataset.id = String(item.id);
            btn.dataset.index = String(index);
            btn.addEventListener('mousedown', function (event) {
                event.preventDefault();
                selectApartment(item.id);
            });
            apartmentList.appendChild(btn);
        });
        apartmentList.classList.add('open');
    }

    function hideApartmentList() {
        apartmentList.classList.remove('open');
        apartmentHighlight = -1;
    }

    function clearApartmentSelection(resetInput) {
        apartmentIdInput.value = '';
        if (resetInput !== false) {
            apartmentSearch.value = '';
        }
        unitSelect.innerHTML = '<option value="">Select room / area</option>';
        unitSelect.disabled = true;
    }

    function selectApartment(id) {
        const apartment = apartmentsById[id];
        if (!apartment) {
            return;
        }
        apartmentIdInput.value = String(apartment.id);
        apartmentSearch.value = apartment.title;
        hideApartmentList();
        loadUnitsForApartment(apartment.id).catch(function (e) {
            setStatus('err', 'Failed to load units: ' + e.message);
        });
    }

    async function loadUnitsForApartment(apartmentId) {
        unitSelect.innerHTML = '<option value="">Select room / area</option>';
        unitSelect.disabled = true;
        const apartment = apartmentsById[apartmentId];
        if (!apartment || apartment.unitIds.length === 0) {
            return;
        }
        const items = await listAllItems(
            CONFIG.unitEntityTypeId,
            { '@id': apartment.unitIds },
            ['id', 'title', 'stageId', 'stageSemanticId']
        );
        items.filter(isActiveStage).sort(function (a, b) {
            return String(a.title || '').localeCompare(String(b.title || ''), undefined, {
                numeric: true,
                sensitivity: 'base',
            });
        }).forEach(function (item) {
            const id = parseInt(item.id, 10);
            if (!id) return;
            const opt = document.createElement('option');
            opt.value = String(id);
            opt.textContent = String(item.title || ('#' + id));
            unitSelect.appendChild(opt);
        });
        unitSelect.disabled = false;
    }

    function renderPhotos() {
        photosWrap.querySelectorAll('.photo-preview, .photo-slot').forEach(function (el) { el.remove(); });
        selectedFiles.forEach(function (file, index) {
            const box = document.createElement('div');
            box.className = 'photo-preview';
            const img = document.createElement('img');
            img.src = URL.createObjectURL(file);
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = '×';
            btn.addEventListener('click', function () {
                selectedFiles.splice(index, 1);
                renderPhotos();
            });
            box.appendChild(img);
            box.appendChild(btn);
            photosWrap.appendChild(box);
        });
        const empty = Math.max(0, 4 - selectedFiles.length);
        for (let i = 0; i < empty; i++) {
            const slot = document.createElement('div');
            slot.className = 'photo-slot';
            photosWrap.appendChild(slot);
        }
    }

    function todayIso() {
        const d = new Date();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return d.getFullYear() + '-' + m + '-' + day;
    }

    function nowTime() {
        const d = new Date();
        let hours = d.getHours();
        let minutes = d.getMinutes() < 30 ? 0 : 30;
        return String(hours).padStart(2, '0') + ':' + String(minutes).padStart(2, '0');
    }

    function fillTimeOptions() {
        timeInput.innerHTML = '<option value="">Select time</option>';
        for (let h = 0; h < 24; h++) {
            for (let m = 0; m < 60; m += 30) {
                const value = String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
                const opt = document.createElement('option');
                opt.value = value;
                opt.textContent = value;
                timeInput.appendChild(opt);
            }
        }
    }

    document.querySelectorAll('#cleaningTypes .type-card').forEach(function (card) {
        card.addEventListener('click', function () {
            document.querySelectorAll('#cleaningTypes .type-card').forEach(function (c) { c.classList.remove('active'); });
            card.classList.add('active');
            cleaningTypeInput.value = card.getAttribute('data-value') || 'Deep Cleaning';
        });
    });

    apartmentSearch.addEventListener('focus', function () {
        renderApartmentList(apartmentSearch.value);
    });
    apartmentSearch.addEventListener('input', function () {
        apartmentIdInput.value = '';
        unitSelect.innerHTML = '<option value="">Select room / area</option>';
        unitSelect.disabled = true;
        renderApartmentList(apartmentSearch.value);
    });
    apartmentSearch.addEventListener('keydown', function (event) {
        const buttons = apartmentList.querySelectorAll('button');
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            if (!apartmentList.classList.contains('open')) {
                renderApartmentList(apartmentSearch.value);
                return;
            }
            apartmentHighlight = Math.min(apartmentHighlight + 1, buttons.length - 1);
            buttons.forEach(function (btn, idx) { btn.classList.toggle('active', idx === apartmentHighlight); });
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            apartmentHighlight = Math.max(apartmentHighlight - 1, 0);
            buttons.forEach(function (btn, idx) { btn.classList.toggle('active', idx === apartmentHighlight); });
        } else if (event.key === 'Enter') {
            if (apartmentHighlight >= 0 && buttons[apartmentHighlight]) {
                event.preventDefault();
                selectApartment(parseInt(buttons[apartmentHighlight].dataset.id, 10));
            }
        } else if (event.key === 'Escape') {
            hideApartmentList();
        }
    });
    apartmentSearch.addEventListener('blur', function () {
        setTimeout(hideApartmentList, 150);
    });

    photoAdd.addEventListener('click', function () { photoInput.click(); });
    photoInput.addEventListener('change', function () {
        const files = Array.prototype.slice.call(photoInput.files || []);
        selectedFiles = selectedFiles.concat(files).slice(0, 10);
        photoInput.value = '';
        renderPhotos();
    });

    document.getElementById('cancelBtn').addEventListener('click', function () {
        if (typeof BX24 !== 'undefined' && BX24.closeApplication) {
            BX24.closeApplication();
        }
    });

    document.getElementById('cleaningForm').addEventListener('submit', async function (event) {
        event.preventDefault();
        clearStatus();

        const apartmentId = parseInt(apartmentIdInput.value, 10);
        const unitId = parseInt(unitSelect.value, 10);
        const apartment = apartmentsById[apartmentId];
        const unitTitle = unitSelect.options[unitSelect.selectedIndex]
            ? unitSelect.options[unitSelect.selectedIndex].textContent
            : '';

        if (!apartmentId || !apartment || apartmentSearch.value.trim() !== apartment.title) {
            setStatus('err', 'Please select apartment from the list.');
            return;
        }
        if (!unitId) {
            setStatus('err', 'Please select room / area.');
            return;
        }
        if (!staffSelect.value) {
            setStatus('err', 'Please select cleaning staff.');
            return;
        }
        if (!dateInput.value || !timeInput.value) {
            setStatus('err', 'Please fill date and time.');
            return;
        }

        const checklist = Array.prototype.slice.call(document.querySelectorAll('input[name="checklist"]:checked'))
            .map(function (el) { return el.value; });
        const otherToggle = document.getElementById('checklistOtherToggle');
        const otherText = (document.getElementById('checklistOther').value || '').trim();
        if (otherToggle.checked && otherText === '') {
            checklist.push('Other');
        }

        const formData = new FormData();
        formData.append('apartment_id', String(apartmentId));
        formData.append('apartment_title', apartment.title);
        formData.append('unit_id', String(unitId));
        formData.append('unit_title', unitTitle || ('#' + unitId));
        formData.append('cleaning_type', cleaningTypeInput.value);
        formData.append('date', dateInput.value);
        formData.append('time', timeInput.value);
        formData.append('staff', staffSelect.value);
        checklist.forEach(function (item) { formData.append('checklist[]', item); });
        formData.append('checklist_other', otherToggle.checked ? otherText : '');
        formData.append('needs_repair', needsRepairInput.checked ? '1' : '0');
        formData.append('notes', notesInput.value || '');
        selectedFiles.forEach(function (file) { formData.append('photos[]', file, file.name); });

        loadingEl.classList.add('show');
        submitBtn.disabled = true;
        try {
            const response = await fetch(CONFIG.submitUrl, {
                method: 'POST',
                body: formData,
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json().catch(function () { return {}; });
            if (!response.ok || data.status !== 'ok') {
                throw new Error((data && data.message) ? data.message : ('Submit failed (' + response.status + ')'));
            }
            setStatus('ok', 'Report submitted. Task #' + data.result.task_id +
                (data.result.repair_task_id ? (', repair task #' + data.result.repair_task_id) : '') + '.');
            document.getElementById('cleaningForm').reset();
            cleaningTypeInput.value = 'Deep Cleaning';
            document.querySelectorAll('#cleaningTypes .type-card').forEach(function (c, idx) {
                c.classList.toggle('active', idx === 0);
            });
            selectedFiles = [];
            renderPhotos();
            clearApartmentSelection(true);
            dateInput.value = todayIso();
            fillTimeOptions();
            timeInput.value = nowTime();
        } catch (e) {
            setStatus('err', e.message || String(e));
        } finally {
            loadingEl.classList.remove('show');
            submitBtn.disabled = false;
        }
    });

    dateInput.value = todayIso();
    fillTimeOptions();
    timeInput.value = nowTime();
    renderPhotos();

    BX24.init(function () {
        loadApartments().catch(function (e) {
            setStatus('err', 'Failed to load apartments: ' + e.message);
        });
    });
})();
</script>
</body>
</html>
