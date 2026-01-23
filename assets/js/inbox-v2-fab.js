/**
 * Inbox V2 - Floating Action Button & HUD Mode Switcher
 * LINE-style UI improvements
 */

// ============================================
// FLOATING ACTION BUTTON (FAB)
// ============================================

const FAB = {
    isOpen: false,

    init() {
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.fab-container') && this.isOpen) {
                this.close();
            }
        });
    },

    toggle() {
        this.isOpen ? this.close() : this.open();
    },

    open() {
        const btn = document.getElementById('fabMainBtn');
        const menu = document.getElementById('fabMenu');
        if (btn && menu) {
            btn.classList.add('active');
            menu.classList.add('show');
            this.isOpen = true;
        }
    },

    close() {
        const btn = document.getElementById('fabMainBtn');
        const menu = document.getElementById('fabMenu');
        if (btn && menu) {
            btn.classList.remove('active');
            menu.classList.remove('show');
            this.isOpen = false;
        }
    },

    action(type) {
        this.close();
        const actions = {
            'order': () => typeof openCreateOrderModal === 'function' && openCreateOrderModal(),
            'payment': () => typeof sendPaymentLink === 'function' && sendPaymentLink(),
            'delivery': () => typeof openScheduleDeliveryModal === 'function' && openScheduleDeliveryModal(),
            'points': () => typeof openUsePointsModal === 'function' && openUsePointsModal(),
            'menu': () => typeof sendRichMenu === 'function' && sendRichMenu(),
            'image': () => typeof toggleImageAnalysisMenu === 'function' && toggleImageAnalysisMenu()
        };
        actions[type] && actions[type]();
    }
};

// ============================================
// HUD MODE SWITCHER
// ============================================

const HUDMode = {
    currentMode: 'crm',
    allTags: [],
    userTags: [],
    collapsedSections: {}, // Store collapsed state
    crmDataLoaded: null,   // Track which user's CRM data is loaded (for lazy loading)

    init() {
        // Check URL params for direct link
        const urlParams = new URLSearchParams(window.location.search);
        const tab = urlParams.get('tab');

        // Determine initial mode
        let initialMode = 'crm';
        if (tab === 'templates') {
            initialMode = 'templates';
        } else {
            initialMode = localStorage.getItem('hudMode') || 'crm';
        }

        this.switchMode(initialMode, false);

        // Load collapsed sections from localStorage
        this.loadCollapsedSections();
    },

    // Load collapsed sections state from localStorage
    loadCollapsedSections() {
        try {
            const saved = localStorage.getItem('hudCollapsedSections');
            if (saved) {
                this.collapsedSections = JSON.parse(saved);
                // Apply saved state to sections
                Object.keys(this.collapsedSections).forEach(sectionId => {
                    const section = document.getElementById(sectionId);
                    if (section && this.collapsedSections[sectionId]) {
                        section.classList.add('collapsed');
                    }
                });
            }
        } catch (e) {
            console.warn('Failed to load collapsed sections:', e);
        }
    },

    // Save collapsed sections state to localStorage
    saveCollapsedSections() {
        try {
            localStorage.setItem('hudCollapsedSections', JSON.stringify(this.collapsedSections));
        } catch (e) {
            console.warn('Failed to save collapsed sections:', e);
        }
    },

    switchMode(mode) {
        // AI mode removed for performance optimization
        if (mode === 'ai') {
            mode = 'crm'; // Fallback to CRM if someone tries to access AI mode
        }

        this.currentMode = mode;
        localStorage.setItem('hudMode', mode);

        document.querySelectorAll('.hud-mode-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.mode === mode);
        });

        const crmPanel = document.getElementById('hudCRMPanel');
        const templatesPanel = document.getElementById('hudTemplatesPanel');

        // Hide all panels
        if (crmPanel) crmPanel.style.display = 'none';
        if (templatesPanel) templatesPanel.style.display = 'none';

        // Show selected panel
        switch (mode) {
            case 'crm':
                if (crmPanel) crmPanel.style.display = 'block';
                this.loadCRMData();
                break;
            case 'templates':
                if (templatesPanel) templatesPanel.style.display = 'block';
                this.loadTemplates();
                break;
        }
    },

    // Templates functions (combined Quick Reply + Templates)
    templates: [],
    filteredTemplates: [],
    templatesLoaded: false,

    async loadTemplates() {
        if (this.templatesLoaded && this.templates.length > 0) {
            this.renderTemplates();
            // Don't return, allow background refresh
        }

        const listContainer = document.getElementById('templateList');
        if (!listContainer) return;

        listContainer.innerHTML = '<div class="text-center text-gray-400 text-sm py-4"><i class="fas fa-spinner fa-spin"></i> กำลังโหลด...</div>';

        try {
            const response = await fetch('api/inbox-v2.php?action=get_templates');
            const data = await response.json();

            if (data.success && data.data) {
                this.templates = data.data;
                this.filteredTemplates = [...this.templates];
                this.templatesLoaded = true;
                this.renderTemplates();
            } else {
                listContainer.innerHTML = '<div class="text-center text-gray-400 text-sm py-4">ไม่พบเทมเพลต</div>';
            }
        } catch (error) {
            console.error('Error loading templates:', error);
            listContainer.innerHTML = '<div class="text-center text-red-400 text-sm py-4">เกิดข้อผิดพลาด</div>';
        }
    },

    searchTemplates(query) {
        const q = query.toLowerCase().trim();
        if (!q) {
            this.filteredTemplates = [...this.templates];
        } else {
            this.filteredTemplates = this.templates.filter(t =>
                t.name.toLowerCase().includes(q) ||
                t.content.toLowerCase().includes(q) ||
                (t.category && t.category.toLowerCase().includes(q)) ||
                (t.shortcut && t.shortcut.toLowerCase().includes(q))
            );
        }
        this.renderTemplates();
    },

    renderTemplates() {
        const listContainer = document.getElementById('templateList');
        if (!listContainer) return;

        if (this.filteredTemplates.length === 0) {
            listContainer.innerHTML = '<div class="text-center text-gray-400 text-sm py-4">ไม่พบเทมเพลต</div>';
            return;
        }

        // Group by category
        const grouped = {};
        this.filteredTemplates.forEach(t => {
            const cat = t.category || 'ทั่วไป';
            if (!grouped[cat]) grouped[cat] = [];
            grouped[cat].push(t);
        });

        let html = '';
        for (const [category, items] of Object.entries(grouped)) {
            html += `<div class="mb-3">
                <div class="text-xs font-medium text-gray-500 mb-2 px-1">${escapeHtml(category)}</div>
                ${items.map(t => `
                    <div class="template-item bg-white border rounded-lg p-3 mb-2 hover:bg-gray-50 hover:border-teal-300 transition group relative">
                        <div class="cursor-pointer" onclick="HUDMode.useTemplate(${t.id})">
                            <div class="flex items-center gap-2 mb-1">
                                ${t.quick_reply ? `<span class="text-xs px-2 py-0.5 bg-teal-100 text-teal-700 rounded font-mono">${escapeHtml(t.quick_reply)}</span>` : ''}
                                <span class="text-sm font-medium text-gray-800 truncate flex-1">${escapeHtml(t.name)}</span>
                            </div>
                            <p class="text-xs text-gray-500 line-clamp-2">${escapeHtml(t.content.substring(0, 100))}${t.content.length > 100 ? '...' : ''}</p>
                        </div>
                        <div class="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition flex gap-1 bg-white/80 rounded">
                            <button onclick="event.stopPropagation(); HUDMode.showTemplateModal('${t.id}')" class="p-1 text-gray-400 hover:text-blue-600" title="แก้ไข">
                                <i class="fas fa-pen"></i>
                            </button>
                            <button onclick="event.stopPropagation(); HUDMode.deleteTemplate('${t.id}')" class="p-1 text-gray-400 hover:text-red-600" title="ลบ">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                `).join('')}
            </div>`;
        }

        listContainer.innerHTML = html;


    },

    useTemplate(id) {
        const template = this.templates.find(t => t.id === id);
        if (!template) return;

        const messageInput = document.getElementById('messageInput');
        if (messageInput) {
            // Replace placeholders
            let content = template.content;
            const userName = window.ghostDraftState?.userName || 'ลูกค้า';
            content = content.replace(/\{name\}/g, userName);
            content = content.replace(/\{ชื่อ\}/g, userName);

            messageInput.value = content;
            messageInput.focus();
            messageInput.dispatchEvent(new Event('input'));

            if (typeof showNotification === 'function') {
                showNotification('✓ นำข้อความลงช่องแชทแล้ว', 'success');
            }
        }
    },

    showTemplateModal(id = null) {
        const modal = document.getElementById('templateModal');
        const title = document.getElementById('templateModalTitle');
        const idInput = document.getElementById('templateId');
        const nameInput = document.getElementById('templateName');
        const contentInput = document.getElementById('templateContent');
        const categoryInput = document.getElementById('templateCategory');
        const quickReplyInput = document.getElementById('templateQuickReply');

        if (id) {
            const t = this.templates.find(x => String(x.id) === String(id));
            if (!t) return;
            title.textContent = 'แก้ไขเทมเพลต';
            idInput.value = t.id;
            nameInput.value = t.name;
            contentInput.value = t.content;
            categoryInput.value = t.category || '';
            quickReplyInput.value = t.quick_reply || '';
        } else {
            title.textContent = 'เพิ่มเทมเพลต';
            idInput.value = '';
            nameInput.value = '';
            contentInput.value = '';
            categoryInput.value = '';
            quickReplyInput.value = '';
        }

        modal.classList.remove('hidden');
    },

    async saveTemplate() {
        const id = document.getElementById('templateId').value;
        const name = document.getElementById('templateName').value.trim();
        const content = document.getElementById('templateContent').value.trim();
        const category = document.getElementById('templateCategory').value.trim();
        const quickReply = document.getElementById('templateQuickReply').value.trim();

        if (!name || !content) {
            alert('กรุณาระบุชื่อและเนื้อหา');
            return;
        }

        const action = id ? 'update_template' : 'create_template';
        const formData = new FormData();
        formData.append('action', action);
        if (id) formData.append('id', id);
        formData.append('name', name);
        formData.append('content', content);
        formData.append('category', category);
        formData.append('quick_reply', quickReply);
        formData.append('line_account_id', window.currentBotId || 1);

        try {
            const response = await fetch('api/inbox-v2.php', { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                document.getElementById('templateModal').classList.add('hidden');
                this.loadTemplates(); // Reload list
                showNotification && showNotification('✓ บันทึกสำเร็จ', 'success');
            } else {
                alert('Error: ' + (result.error || result.message));
            }
        } catch (error) {
            console.error('Save template error:', error);
            alert('บันทึกไม่สำเร็จ');
        }
    },

    async deleteTemplate(id) {
        if (!confirm('ต้องการลบเทมเพลตนี้?')) return;

        const formData = new FormData();
        formData.append('action', 'delete_template');
        formData.append('id', id);

        try {
            const response = await fetch('api/inbox-v2.php', { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                this.loadTemplates(); // Reload list
                showNotification && showNotification('✓ ลบสำเร็จ', 'success');
            } else {
                alert('Error: ' + (result.error || result.message));
            }
        } catch (error) {
            console.error('Delete template error:', error);
        }
    },

    openTemplateManager() {
        window.open('inbox.php?tab=templates', '_blank');
    },

    toggleSection(sectionId) {
        const section = document.getElementById(sectionId);
        if (section) {
            section.classList.toggle('collapsed');
            // Save state to localStorage
            this.collapsedSections[sectionId] = section.classList.contains('collapsed');
            this.saveCollapsedSections();
        }
    },

    async loadCRMData(force = false) {
        const userId = window.ghostDraftState?.userId;
        if (!userId) {
            return;
        }

        // Lazy load: Only load if HUD is visible (performance optimization)
        const hud = document.getElementById('hudDashboard');
        if (hud && hud.classList.contains('collapsed')) {
            console.log('[HUDMode] CRM data lazy load skipped - HUD is collapsed');
            return;
        }

        // Skip if already loaded for this user (unless forced)
        if (!force && this.crmDataLoaded === userId) {
            return;
        }

        try {
            // Add timestamp to prevent caching
            const url = `api/inbox-v2.php?action=customer_crm&user_id=${userId}&line_account_id=${window.currentBotId || 1}&_t=${new Date().getTime()}`;
            const response = await fetch(url);
            const result = await response.json();

            if (result.success && result.data) {
                this.renderCRMData(result.data);
                this.crmDataLoaded = userId; // Mark as loaded for this user
            } else {
                console.error('[HUDMode] CRM data error:', result.error);
            }
        } catch (error) {
            console.error('Load CRM data error:', error);
        }
    },

    renderCRMData(data) {
        // Points
        const pointsDisplay = document.getElementById('crmPointsDisplay');
        if (pointsDisplay) pointsDisplay.textContent = (data.points?.available_points || 0).toLocaleString();

        // Tier
        const tierBadge = document.getElementById('crmTierBadge');
        if (tierBadge && data.tier) tierBadge.innerHTML = `${data.tier.icon} ${data.tier.name}`;

        // Stats
        if (data.stats) {
            const el = (id, val) => { const e = document.getElementById(id); if (e) e.textContent = val; };
            el('crmOrderCount', (data.stats.order_count || 0).toLocaleString());
            el('crmTotalSpent', '฿' + (data.stats.total_spent || 0).toLocaleString());
            el('crmMsgCount', (data.stats.message_count || 0).toLocaleString());
        }

        // Customer info
        if (data.user) {
            this.renderCustomerInfo(data.user);
            // Update chat status dropdown
            const chatStatusSelect = document.getElementById('crmChatStatus');
            if (chatStatusSelect) {
                chatStatusSelect.value = data.user.chat_status || '';
            }
        }

        // Tags
        this.allTags = data.all_tags || [];
        this.userTags = data.tags || [];
        this.renderTags();

        // Notes
        this.renderNotes(data.notes || []);

        // Transactions
        this.renderTransactions(data.transactions || []);

        // Load assignment info
        this.loadAssignment();
    },

    async loadAssignment() {
        const userId = window.ghostDraftState?.userId;
        if (!userId) return;

        try {
            const response = await fetch(`api/inbox-v2.php?action=get_assignment&user_id=${userId}&line_account_id=${window.currentBotId || 1}`);
            const result = await response.json();

            if (result.success) {
                this.currentAssignment = result.data;
                this.updateAssignedDisplay();
            }
        } catch (error) {
            console.error('Load assignment error:', error);
        }
    },

    renderCustomerInfo(user) {
        const fields = [
            { id: 'crm_display_name', field: 'display_name', label: 'ชื่อ' },
            { id: 'crm_phone', field: 'phone', label: 'เบอร์โทร' },
            { id: 'crm_address', field: 'address', label: 'ที่อยู่' }
        ];

        fields.forEach(f => {
            const container = document.getElementById(`${f.id}_container`);
            if (container && !container.classList.contains('editing')) {
                const value = user[f.field] || '-';
                const rawValue = user[f.field] || '';
                container.innerHTML = `
                    <div class="info-left">
                        <div class="label">${f.label}</div>
                        <div class="value" id="${f.id}">${escapeHtml(value)}</div>
                    </div>
                    <button class="edit-btn" onclick="HUDMode.editField('${f.field}', '${escapeHtml(rawValue).replace(/'/g, "\\'")}')">
                        <i class="fas fa-pen"></i>
                    </button>
                `;
            }
        });
    },

    editField(field, currentValue) {
        const container = document.getElementById(`crm_${field}_container`);
        if (!container) return;

        const label = container.querySelector('.label').textContent;
        container.classList.add('editing');
        container.innerHTML = `
            <div class="label">${label}</div>
            <input type="text" class="edit-input" id="edit_${field}" value="${escapeHtml(currentValue || '')}" placeholder="กรอก${label}...">
            <div class="edit-actions">
                <button class="cancel-btn" onclick="HUDMode.cancelEdit('${field}', '${escapeHtml(currentValue || '-')}', '${label}')">ยกเลิก</button>
                <button class="save-btn" onclick="HUDMode.saveField('${field}')">บันทึก</button>
            </div>
        `;
        document.getElementById(`edit_${field}`).focus();
    },

    cancelEdit(field, value, label) {
        const container = document.getElementById(`crm_${field}_container`);
        if (!container) return;

        container.classList.remove('editing');
        container.innerHTML = `
            <div class="info-left">
                <div class="label">${label}</div>
                <div class="value" id="crm_${field}">${value}</div>
            </div>
            <button class="edit-btn" onclick="HUDMode.editField('${field}', '${escapeHtml(value === '-' ? '' : value)}')">
                <i class="fas fa-pen"></i>
            </button>
        `;
    },

    async saveField(field) {
        const input = document.getElementById(`edit_${field}`);
        const value = input?.value?.trim() || '';
        const userId = window.ghostDraftState?.userId;

        if (!userId) {
            showNotification && showNotification('❌ ไม่พบข้อมูลลูกค้า', 'error');
            return;
        }

        try {
            const formData = new FormData();
            formData.append('action', 'update_customer_info');
            formData.append('user_id', userId);
            formData.append('field', field);
            formData.append('value', value);
            formData.append('line_account_id', window.currentBotId || 1);

            const response = await fetch('api/inbox-v2.php', { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                this.loadCRMData();
                showNotification && showNotification('✓ บันทึกสำเร็จ', 'success');
            } else {
                showNotification && showNotification('❌ ' + (result.error || 'เกิดข้อผิดพลาด'), 'error');
            }
        } catch (error) {
            console.error('Save field error:', error);
            showNotification && showNotification('❌ เกิดข้อผิดพลาด', 'error');
        }
    },

    renderTags() {
        const container = document.getElementById('crmTagsContainer');
        if (!container) return;

        let html = this.userTags.map(tag => `
            <span class="tag-badge" style="background-color: ${tag.color || '#6B7280'} !important">
                ${escapeHtml(tag.name)}
                <span class="remove-tag" onclick="HUDMode.removeTag(${tag.id})">&times;</span>
            </span>
        `).join('');

        html += `<button class="add-tag-btn" onclick="HUDMode.showTagSelector()">+ เพิ่ม Tag</button>`;
        html += `<div id="tagSelectorContainer"></div>`;

        container.innerHTML = html;
    },

    showTagSelector() {
        const container = document.getElementById('tagSelectorContainer');
        if (!container) {
            console.error('[HUDMode] tagSelectorContainer not found');
            return;
        }

        // Filter out already assigned tags
        const userTagIds = this.userTags.map(t => t.id);
        const availableTags = this.allTags.filter(t => !userTagIds.includes(t.id));

        let html = `<div class="tag-selector">`;

        if (availableTags.length > 0) {
            html += `<div class="tag-selector-title">เลือก Tag ที่มีอยู่</div>`;
            html += `<div class="tag-selector-list">`;
            availableTags.forEach(tag => {
                html += `<span class="tag-selector-item" style="background-color: ${tag.color || '#6B7280'}; color: white;" onclick="HUDMode.addExistingTag(${tag.id})">${escapeHtml(tag.name)}</span>`;
            });
            html += `</div>`;
        } else {
            html += `<div class="tag-selector-title">ไม่มี Tag ที่สามารถเพิ่มได้</div>`;
        }

        html += `
            <div class="tag-selector-new">
                <input type="text" id="newTagInput" placeholder="หรือสร้าง Tag ใหม่..." onkeypress="if(event.key==='Enter')HUDMode.addNewTag()">
                <button onclick="HUDMode.addNewTag()">เพิ่ม</button>
            </div>
        </div>`;

        container.innerHTML = html;
    },

    hideTagSelector() {
        const container = document.getElementById('tagSelectorContainer');
        if (container) container.innerHTML = '';
    },

    async addExistingTag(tagId) {
        const userId = window.ghostDraftState?.userId;
        if (!userId) return;

        try {
            const formData = new FormData();
            formData.append('action', 'assign_tag');
            formData.append('user_id', userId);
            formData.append('tag_id', tagId);
            formData.append('line_account_id', window.currentBotId || 1);

            const response = await fetch('api/inbox-v2.php', { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                this.hideTagSelector();
                this.loadCRMData(true); // Force reload to show new tag immediately
                showNotification && showNotification('✓ เพิ่ม Tag สำเร็จ', 'success');
            }
        } catch (error) {
            console.error('Add tag error:', error);
        }
    },

    async addNewTag() {
        const input = document.getElementById('newTagInput');
        const tagName = input?.value?.trim();
        if (!tagName) return;

        const userId = window.ghostDraftState?.userId;
        if (!userId) return;

        try {
            const formData = new FormData();
            formData.append('action', 'add_customer_tag');
            formData.append('user_id', userId);
            formData.append('tag_name', tagName);
            formData.append('line_account_id', window.currentBotId || 1);

            const response = await fetch('api/inbox-v2.php', { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                this.hideTagSelector();
                this.loadCRMData(true); // Force reload to show new tag immediately
                showNotification && showNotification('✓ เพิ่ม Tag สำเร็จ', 'success');
            }
        } catch (error) {
            console.error('Add new tag error:', error);
        }
    },

    async removeTag(tagId) {
        const userId = window.ghostDraftState?.userId;
        if (!userId) return;

        try {
            const formData = new FormData();
            formData.append('action', 'remove_customer_tag');
            formData.append('user_id', userId);
            formData.append('tag_id', tagId);
            formData.append('line_account_id', window.currentBotId || 1);

            const response = await fetch('api/inbox-v2.php', { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                this.loadCRMData(true); // Force reload to remove tag immediately
            }
        } catch (error) {
            console.error('Remove tag error:', error);
        }
    },

    renderNotes(notes) {
        const container = document.getElementById('crmNotesList');
        if (!container) return;

        if (notes.length === 0) {
            container.innerHTML = '<div class="notes-empty">ยังไม่มีโน้ต</div>';
            return;
        }

        container.innerHTML = notes.slice(0, 10).map(note => `
            <div class="note-item">
                <div>${escapeHtml(note.content)}</div>
                <div class="note-meta">
                    <span>${note.created_by || 'Admin'} • ${formatDate(note.created_at)}</span>
                    <span class="delete-note" onclick="HUDMode.deleteNote(${note.id})"><i class="fas fa-trash"></i></span>
                </div>
            </div>
        `).join('');
    },

    async addNote() {
        const textarea = document.getElementById('crmNoteInput');
        const content = textarea?.value?.trim();
        if (!content) return;

        const userId = window.ghostDraftState?.userId;
        if (!userId) return;

        const btn = document.querySelector('.add-note-btn');
        if (btn) btn.disabled = true;

        try {
            const formData = new FormData();
            formData.append('action', 'add_customer_note');
            formData.append('user_id', userId);
            formData.append('content', content);
            formData.append('line_account_id', window.currentBotId || 1);

            const response = await fetch('api/inbox-v2.php', { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                textarea.value = '';
                setTimeout(() => this.loadCRMData(true), 100); // Delay to ensure DB commit
                showNotification && showNotification('✓ เพิ่มโน้ตสำเร็จ', 'success');
            }
        } catch (error) {
            console.error('Add note error:', error);
        } finally {
            if (btn) btn.disabled = false;
        }
    },

    async deleteNote(noteId) {
        if (!confirm('ต้องการลบโน้ตนี้?')) return;

        try {
            const formData = new FormData();
            formData.append('action', 'delete_customer_note');
            formData.append('note_id', noteId);
            formData.append('line_account_id', window.currentBotId || 1);

            const response = await fetch('api/inbox-v2.php', { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                this.loadCRMData();
            }
        } catch (error) {
            console.error('Delete note error:', error);
        }
    },

    renderTransactions(transactions) {
        const container = document.getElementById('crmTransactionsList');
        if (!container) return;

        if (transactions.length === 0) {
            container.innerHTML = '<div class="notes-empty">ยังไม่มีรายการ</div>';
            return;
        }

        container.innerHTML = transactions.slice(0, 5).map(tx => `
            <div class="transaction-mini-item">
                <div class="tx-info">
                    <span class="tx-id">#${tx.id}</span>
                    <span class="tx-date">${formatDate(tx.created_at)}</span>
                </div>
                <span class="tx-amount">฿${(tx.grand_total || 0).toLocaleString()}</span>
            </div>
        `).join('');
    },

    openUserDetail() {
        const userId = window.ghostDraftState?.userId;
        if (userId) window.open(`user-detail.php?id=${userId}`, '_blank');
    },

    async updateChatStatus(status) {
        const userId = window.ghostDraftState?.userId;
        if (!userId) {
            showNotification && showNotification('❌ ไม่พบข้อมูลลูกค้า', 'error');
            return;
        }

        try {
            const formData = new FormData();
            formData.append('action', 'update_chat_status');
            formData.append('user_id', userId);
            formData.append('status', status);
            formData.append('line_account_id', window.currentBotId || 1);

            const response = await fetch('/api/inbox-v2.php', { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                showNotification && showNotification('✓ อัปเดตสถานะสำเร็จ', 'success');

                // Update the user-item in sidebar
                const userItem = document.querySelector(`[data-user-id="${userId}"]`);
                if (userItem) {
                    // Update data attribute
                    userItem.dataset.chatStatus = status;

                    // Update the visible status badge
                    this.updateStatusBadgeInSidebar(userItem, status);
                }
            } else {
                showNotification && showNotification('❌ ' + (result.error || 'เกิดข้อผิดพลาด'), 'error');
            }
        } catch (error) {
            console.error('Update chat status error:', error);
            showNotification && showNotification('❌ เกิดข้อผิดพลาด', 'error');
        }
    },

    /**
     * Update the status badge in sidebar after status change
     */
    updateStatusBadgeInSidebar(userItem, status) {
        const statusBadges = {
            'pending': { icon: '🔴', color: '#EF4444', bg: '#FEE2E2' },
            'completed': { icon: '🟢', color: '#10B981', bg: '#D1FAE5' },
            'shipping': { icon: '📦', color: '#F59E0B', bg: '#FEF3C7' },
            'tracking': { icon: '🚚', color: '#3B82F6', bg: '#DBEAFE' },
            'billing': { icon: '💰', color: '#8B5CF6', bg: '#EDE9FE' }
        };

        // Find badge container
        let badgeContainer = userItem.querySelector('.flex.items-center.gap-1.mt-1');
        if (!badgeContainer) {
            // Create container if doesn't exist
            const flex1 = userItem.querySelector('.flex-1.min-w-0');
            if (flex1) {
                badgeContainer = document.createElement('div');
                badgeContainer.className = 'flex items-center gap-1 mt-1 flex-wrap';
                flex1.appendChild(badgeContainer);
            }
        }

        if (!badgeContainer) return;

        // Remove existing status badge
        const existingBadge = badgeContainer.querySelector('.chat-status-badge');
        if (existingBadge) {
            existingBadge.remove();
        }

        // Add new badge if status is set
        if (status && statusBadges[status]) {
            const badge = statusBadges[status];
            const newBadge = document.createElement('span');
            newBadge.className = 'chat-status-badge';
            newBadge.style.cssText = `background: ${badge.bg}; color: ${badge.color}; border: 1px solid ${badge.color}30;`;
            newBadge.textContent = badge.icon;
            badgeContainer.insertBefore(newBadge, badgeContainer.firstChild);
        }

        console.log(`[HUDMode] Updated status badge for user ${userItem.dataset.userId} to ${status || 'none'}`);
    },

    // ============================================
    // ASSIGN TASK FUNCTIONS (Multi-Assignee Support)
    // ============================================

    adminList: [],
    selectedAdminIds: [], // Changed to array for multi-select
    currentAssignment: null,

    async showAssignModal() {
        const userId = window.ghostDraftState?.userId;
        if (!userId) {
            showNotification && showNotification('❌ ไม่พบข้อมูลลูกค้า', 'error');
            return;
        }

        // Create modal if not exists
        let modal = document.getElementById('assignModalOverlay');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'assignModalOverlay';
            modal.className = 'assign-modal-overlay';
            modal.innerHTML = `
                <div class="assign-modal">
                    <div class="assign-modal-header">
                        <h3><i class="fas fa-user-plus"></i> มอบหมายงาน (เลือกได้หลายคน)</h3>
                        <button class="assign-modal-close" onclick="HUDMode.hideAssignModal()">&times;</button>
                    </div>
                    <div class="assign-modal-body">
                        <div class="assign-selected-count" id="assignSelectedCount" style="display:none;">
                            <i class="fas fa-check-circle"></i> เลือกแล้ว <span id="assignCountNumber">0</span> คน
                        </div>
                        <div id="assignAdminList" class="assign-admin-list">
                            <div class="assign-loading"><i class="fas fa-spinner fa-spin"></i> กำลังโหลด...</div>
                        </div>
                    </div>
                    <div class="assign-modal-footer">
                        <button class="assign-cancel-btn" onclick="HUDMode.hideAssignModal()">ยกเลิก</button>
                        <button class="assign-confirm-btn" id="assignConfirmBtn" onclick="HUDMode.confirmAssign()" disabled>มอบหมาย</button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
        }

        // Reset selection
        this.selectedAdminIds = [];

        // Show modal
        setTimeout(() => modal.classList.add('show'), 10);

        // Load admin list and current assignment
        await this.loadAdminList();
        await this.loadCurrentAssignment();
    },

    hideAssignModal() {
        const modal = document.getElementById('assignModalOverlay');
        if (modal) {
            modal.classList.remove('show');
        }
        this.selectedAdminIds = [];
    },

    async loadCurrentAssignment() {
        const userId = window.ghostDraftState?.userId;
        if (!userId) return;

        try {
            const response = await fetch(`api/inbox-v2.php?action=get_assignment&user_id=${userId}&line_account_id=${window.currentBotId || 1}`);
            const result = await response.json();

            if (result.success && result.data) {
                this.currentAssignment = result.data;

                // Pre-select currently assigned admins
                if (result.data.assignees && Array.isArray(result.data.assignees)) {
                    this.selectedAdminIds = result.data.assignees.map(a => a.admin_id);
                    this.updateSelectedCount();
                    this.renderAdminList();
                }
            }
        } catch (error) {
            console.error('Load assignment error:', error);
        }
    },

    async loadAdminList() {
        const listContainer = document.getElementById('assignAdminList');
        if (!listContainer) return;

        try {
            const response = await fetch(`api/inbox-v2.php?action=get_admins&line_account_id=${window.currentBotId || 1}`);
            const result = await response.json();

            if (result.success && result.data) {
                this.adminList = result.data;
                this.renderAdminList();
            } else {
                listContainer.innerHTML = '<div class="assign-loading">ไม่พบข้อมูลผู้ดูแล</div>';
            }
        } catch (error) {
            console.error('Load admin list error:', error);
            listContainer.innerHTML = '<div class="assign-loading">เกิดข้อผิดพลาด</div>';
        }
    },

    renderAdminList() {
        const listContainer = document.getElementById('assignAdminList');
        if (!listContainer || this.adminList.length === 0) {
            listContainer.innerHTML = '<div class="assign-loading">ไม่พบข้อมูลผู้ดูแล</div>';
            return;
        }

        listContainer.innerHTML = this.adminList.map(admin => {
            const isSelected = this.selectedAdminIds.includes(admin.id);
            return `
                <div class="assign-admin-item ${isSelected ? 'selected' : ''}" 
                     onclick="HUDMode.toggleAdmin(${admin.id})">
                    <div class="assign-admin-checkbox">
                        <i class="fas fa-${isSelected ? 'check-square' : 'square'}"></i>
                    </div>
                    <div class="assign-admin-avatar">
                        ${admin.display_name ? admin.display_name.charAt(0).toUpperCase() : 'A'}
                    </div>
                    <div class="assign-admin-info">
                        <div class="assign-admin-name">${escapeHtml(admin.display_name || admin.username || 'Admin')}</div>
                        <div class="assign-admin-role">${escapeHtml(admin.role || 'Staff')}</div>
                    </div>
                </div>
            `;
        }).join('');
    },

    toggleAdmin(adminId) {
        const index = this.selectedAdminIds.indexOf(adminId);
        if (index > -1) {
            // Remove from selection
            this.selectedAdminIds.splice(index, 1);
        } else {
            // Add to selection
            this.selectedAdminIds.push(adminId);
        }

        this.updateSelectedCount();
        this.renderAdminList();

        const confirmBtn = document.getElementById('assignConfirmBtn');
        if (confirmBtn) {
            confirmBtn.disabled = this.selectedAdminIds.length === 0;
        }
    },

    updateSelectedCount() {
        const countContainer = document.getElementById('assignSelectedCount');
        const countNumber = document.getElementById('assignCountNumber');

        if (countContainer && countNumber) {
            countNumber.textContent = this.selectedAdminIds.length;
            countContainer.style.display = this.selectedAdminIds.length > 0 ? 'block' : 'none';
        }
    },

    async confirmAssign() {
        if (this.selectedAdminIds.length === 0) return;

        const userId = window.ghostDraftState?.userId;
        if (!userId) {
            showNotification && showNotification('❌ ไม่พบข้อมูลลูกค้า', 'error');
            return;
        }

        const confirmBtn = document.getElementById('assignConfirmBtn');
        if (confirmBtn) confirmBtn.disabled = true;

        try {
            const formData = new FormData();
            formData.append('action', 'assign_conversation');
            formData.append('user_id', userId);
            formData.append('assign_to', JSON.stringify(this.selectedAdminIds)); // Send as JSON array
            formData.append('line_account_id', window.currentBotId || 1);

            const response = await fetch('api/inbox-v2.php', { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                const count = result.assigned_count || this.selectedAdminIds.length;
                showNotification && showNotification(`✓ มอบหมายงานให้ ${count} คนสำเร็จ`, 'success');
                this.hideAssignModal();
                this.loadCRMData(true); // Force Refresh
                // this.updateAssignedDisplay(); // Removed as loadCRMData(true) handles it
            } else {
                showNotification && showNotification('❌ ' + (result.error || 'เกิดข้อผิดพลาด'), 'error');
            }
        } catch (error) {
            console.error('Assign conversation error:', error);
            showNotification && showNotification('❌ เกิดข้อผิดพลาด', 'error');
        } finally {
            if (confirmBtn) confirmBtn.disabled = false;
        }
    },

    async unassignConversation(adminId = null) {
        const userId = window.ghostDraftState?.userId;
        if (!userId) return;

        const message = adminId
            ? 'ต้องการยกเลิกการมอบหมายให้คนนี้?'
            : 'ต้องการยกเลิกการมอบหมายทั้งหมด?';

        if (!confirm(message)) return;

        try {
            const formData = new FormData();
            formData.append('action', 'unassign_conversation');
            formData.append('user_id', userId);
            if (adminId) formData.append('admin_id', adminId);
            formData.append('line_account_id', window.currentBotId || 1);

            const response = await fetch('api/inbox-v2.php', { method: 'POST', body: formData });
            const result = await response.json();

            if (result.success) {
                showNotification && showNotification('✓ ยกเลิกการมอบหมายแล้ว', 'success');
                this.loadCRMData(true); // Force Refresh
                // this.updateAssignedDisplay(); // Removed as loadCRMData(true) handles it
            }
        } catch (error) {
            console.error('Unassign error:', error);
        }
    },

    updateAssignedDisplay() {
        const display = document.getElementById('assignedToDisplay');
        if (!display) return;

        if (this.currentAssignment && this.currentAssignment.assignees && this.currentAssignment.assignees.length > 0) {
            const assignees = this.currentAssignment.assignees;
            const names = assignees.map(a => {
                const admin = this.adminList.find(ad => ad.id === a.admin_id);
                return admin ? (admin.display_name || admin.username) : a.username || 'Admin';
            });

            display.innerHTML = `
                <div class="assigned-list">
                    <span><i class="fas fa-user-check"></i> มอบหมายให้ ${assignees.length} คน:</span>
                    <div class="assigned-names">
                        ${assignees.map((a, idx) => `
                            <span class="assigned-badge">
                                ${escapeHtml(names[idx])}
                                <button class="remove-assignee-btn" onclick="HUDMode.unassignConversation(${a.admin_id})" title="ยกเลิก">
                                    <i class="fas fa-times"></i>
                                </button>
                            </span>
                        `).join('')}
                    </div>
                    <button class="unassign-all-btn" onclick="HUDMode.unassignConversation()">ยกเลิกทั้งหมด</button>
                </div>
            `;
            display.classList.add('show');
        } else {
            display.classList.remove('show');
            display.innerHTML = '';
        }
    }
};

// Helper functions
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatDate(dateStr) {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    return date.toLocaleDateString('th-TH', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
}

// Initialize
document.addEventListener('DOMContentLoaded', function () {
    FAB.init();
    HUDMode.init();
});

/* Slash Command & Input Helpers */
window.autoResize = function (textarea) {
    textarea.style.height = 'auto';
    textarea.style.height = textarea.scrollHeight + 'px';
};

window.openQuickReplyModal = function () {
    const list = document.getElementById('slashCommandList');
    const container = document.getElementById('slashCommandAutocomplete');
    if (!list || !container) return;

    // Ensure templates are loaded
    if (!HUDMode.templatesLoaded) {
        HUDMode.loadTemplates().then(() => window.openQuickReplyModal());
        return;
    }

    // Prepare list
    const templates = HUDMode.templates || [];

    if (templates.length === 0) {
        list.innerHTML = '<div class="p-3 text-sm text-gray-500 text-center">ไม่พบเทมเพลต</div>';
    } else {
        list.innerHTML = templates.map(t => {
            const name = escapeHtml(t.name);
            const content = escapeHtml(t.content);
            const quickReply = t.quick_reply ? '<span class="text-xs px-1.5 py-0.5 bg-indigo-50 text-indigo-600 rounded font-mono border border-indigo-100">/' + escapeHtml(t.quick_reply) + '</span>' : '';

            return `
            <div class="p-3 hover:bg-teal-50 cursor-pointer border-b border-gray-100 last:border-0 transition-colors duration-150 group" 
                 onclick="window.insertSlashTemplate(${t.id})">
                <div class="flex items-center justify-between mb-1">
                    <span class="font-semibold text-sm text-gray-800 group-hover:text-teal-700">${name}</span>
                    ${quickReply}
                </div>
                <div class="text-xs text-gray-500 truncate font-light">${content}</div>
            </div>
            `;
        }).join('');
    }

    container.classList.remove('hidden');

    // Add click outside listener
    setTimeout(() => {
        document.addEventListener('click', window.closeSlashOnClickOutside);
    }, 0);
};

window.closeQuickReplyModal = function () {
    const container = document.getElementById('slashCommandAutocomplete');
    if (container) container.classList.add('hidden');
    document.removeEventListener('click', window.closeSlashOnClickOutside);
};

window.closeSlashOnClickOutside = function (e) {
    const container = document.getElementById('slashCommandAutocomplete');
    const input = document.getElementById('messageInput');
    // If click is NOT in container AND NOT in input
    if (container && !container.contains(e.target) && e.target !== input) {
        window.closeQuickReplyModal();
    }
};

window.insertSlashTemplate = function (id) {
    HUDMode.useTemplate(id);
    window.closeQuickReplyModal();
};
