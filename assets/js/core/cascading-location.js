(function (global) {
    'use strict';

    var STEPS = ['country', 'province', 'municipality', 'barangay'];
    var STEP_LABELS = {
        country: 'Country',
        province: 'Province',
        municipality: 'City / Municipality',
        barangay: 'Barangay'
    };

    function formatBarangay(name) {
        name = String(name || '').trim();
        if (!name) return '';
        if (/^barangay\s+/i.test(name)) return name;
        return 'Barangay ' + name;
    }

    function formatValueFull(selection) {
        var parts = [];
        if (selection.country) parts.push(selection.country);
        if (selection.province) parts.push(selection.province);
        if (selection.municipality) parts.push(selection.municipality);
        if (selection.barangay) parts.push(formatBarangay(selection.barangay));
        return parts.join(', ');
    }

    function formatValuePhBirth(selection) {
        var parts = [];
        if (selection.barangay) parts.push(formatBarangay(selection.barangay));
        if (selection.municipality) parts.push(selection.municipality);
        if (selection.province) parts.push(selection.province);
        return parts.join(', ');
    }

    function formatValuePhResidence(selection) {
        if (selection.countryCode && selection.countryCode !== 'PH') {
            return selection.country || '';
        }
        var parts = [];
        if (selection.barangay) parts.push(formatBarangay(selection.barangay));
        if (selection.municipality) parts.push(selection.municipality);
        if (selection.province) parts.push(selection.province);
        if (selection.country) parts.push(selection.country);
        return parts.join(', ');
    }

    function formatValuePhMarriage(selection) {
        if (selection.countryCode && selection.countryCode !== 'PH') {
            return selection.country || '';
        }
        var parts = [];
        if (selection.municipality) parts.push(selection.municipality);
        if (selection.province) parts.push(selection.province);
        if (selection.country) parts.push(selection.country);
        return parts.join(', ');
    }

    function formatValueForMode(selection, mode) {
        if (mode === 'ph_birth_place') {
            return formatValuePhBirth(selection);
        }
        if (mode === 'ph_residence') {
            return formatValuePhResidence(selection);
        }
        if (mode === 'ph_marriage_place') {
            return formatValuePhMarriage(selection);
        }
        return formatValueFull(selection);
    }

    function partialValue(selection, mode) {
        return formatValueForMode(selection, mode);
    }

    function normalizeText(value) {
        return String(value || '').trim().toLowerCase();
    }

    function stripBarangayPrefix(value) {
        return String(value || '').replace(/^barangay\s+/i, '').trim();
    }

    function fetchItems(apiUrl, params) {
        var url = new URL(apiUrl, window.location.href);
        Object.keys(params || {}).forEach(function (key) {
            if (params[key] !== undefined && params[key] !== null && params[key] !== '') {
                url.searchParams.set(key, params[key]);
            }
        });
        return fetch(url.toString(), { credentials: 'same-origin' })
            .then(function (res) {
                return res.json().then(function (data) {
                    if (!res.ok || !data.ok) {
                        throw new Error((data && (data.error || data.message)) || 'Location lookup failed.');
                    }
                    return data.items || [];
                });
            });
    }

    function CascadingLocationPicker(input, options) {
        this.input = input;
        this.apiUrl = options.apiUrl;
        this.mode = options.mode || input.dataset.locationMode || 'full';
        if (this.mode === 'ph_birth_place') {
            this.steps = ['province', 'municipality', 'barangay'];
        } else if (this.mode === 'ph_marriage_place') {
            this.steps = ['country', 'province', 'municipality'];
        } else {
            this.steps = STEPS.slice();
        }
        this.selection = {
            countryCode: '',
            country: '',
            provinceId: 0,
            province: '',
            municipalityId: 0,
            municipality: '',
            barangayId: 0,
            barangay: ''
        };
        this.stepIndex = 0;
        this.items = [];
        this.open = false;
        this.loading = false;
        this.onScrollOrResize = this.positionPanel.bind(this);
        this.loadError = '';

        this.root = document.createElement('div');
        this.root.className = 'cascading-location';
        this.input.parentNode.insertBefore(this.root, this.input);
        this.root.appendChild(this.input);

        this.input.classList.add('cascading-location__input');
        this.input.setAttribute('autocomplete', 'off');
        this.input.setAttribute('readonly', 'readonly');
        this.input.setAttribute('role', 'combobox');
        this.input.setAttribute('aria-haspopup', 'listbox');
        this.input.setAttribute('aria-expanded', 'false');

        this.panel = document.createElement('div');
        this.panel.className = 'cascading-location__panel';
        this.panel.hidden = true;
        this.root.appendChild(this.panel);

        this.stepEl = document.createElement('div');
        this.stepEl.className = 'cascading-location__step';
        this.panel.appendChild(this.stepEl);

        this.searchEl = document.createElement('input');
        this.searchEl.type = 'search';
        this.searchEl.className = 'cascading-location__search';
        this.searchEl.placeholder = 'Type to filter…';
        this.searchEl.setAttribute('autocomplete', 'off');
        this.panel.appendChild(this.searchEl);

        this.listEl = document.createElement('div');
        this.listEl.className = 'cascading-location__list';
        this.listEl.setAttribute('role', 'listbox');
        this.panel.appendChild(this.listEl);

        if (this.mode === 'ph_birth_place') {
            this.selection.countryCode = 'PH';
            this.selection.country = 'Philippines';
        }

        this.bindEvents();
        this.seedFromValue(this.input.value || '');
    }

    CascadingLocationPicker.prototype.formatValue = function () {
        return formatValueForMode(this.selection, this.mode);
    };

    CascadingLocationPicker.prototype.bindEvents = function () {
        var self = this;

        this.input.addEventListener('click', function (e) {
            e.stopPropagation();
            self.toggle(!self.open);
        });

        this.input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') {
                e.preventDefault();
                self.toggle(true);
            } else if (e.key === 'Escape') {
                self.toggle(false);
            }
        });

        this.searchEl.addEventListener('input', function () {
            self.renderList();
        });

        this.searchEl.addEventListener('click', function (e) {
            e.stopPropagation();
        });

        this.panel.addEventListener('mousedown', function (e) {
            e.preventDefault();
        });

        this.panel.addEventListener('click', function (e) {
            e.stopPropagation();
        });

        document.addEventListener('click', function () {
            if (self.open) {
                self.toggle(false);
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && self.open) {
                self.toggle(false);
            }
        });
    };

    CascadingLocationPicker.prototype.getScrollParents = function () {
        var parents = [];
        var node = this.input.parentElement;
        while (node && node !== document.body) {
            var style = window.getComputedStyle(node);
            if (/(auto|scroll|overlay)/.test(style.overflow + style.overflowY)) {
                parents.push(node);
            }
            node = node.parentElement;
        }
        return parents;
    };

    CascadingLocationPicker.prototype.bindPanelPositionListeners = function () {
        window.addEventListener('resize', this.onScrollOrResize);
        window.addEventListener('scroll', this.onScrollOrResize, true);
        this.scrollParents = this.getScrollParents();
        this.scrollParents.forEach(function (el) {
            el.addEventListener('scroll', this.onScrollOrResize, { passive: true });
        }, this);
    };

    CascadingLocationPicker.prototype.unbindPanelPositionListeners = function () {
        window.removeEventListener('resize', this.onScrollOrResize);
        window.removeEventListener('scroll', this.onScrollOrResize, true);
        if (this.scrollParents) {
            this.scrollParents.forEach(function (el) {
                el.removeEventListener('scroll', this.onScrollOrResize);
            }, this);
        }
        this.scrollParents = null;
    };

    CascadingLocationPicker.prototype.positionPanel = function () {
        if (!this.open || this.panel.hidden) {
            return;
        }

        var rect = this.input.getBoundingClientRect();
        var width = Math.max(rect.width, 220);
        var left = Math.min(rect.left, window.innerWidth - width - 8);
        left = Math.max(8, left);

        this.panel.style.position = 'fixed';
        this.panel.style.left = left + 'px';
        this.panel.style.width = width + 'px';
        this.panel.style.right = 'auto';
        this.panel.style.zIndex = '120';

        this.panel.style.visibility = 'hidden';
        this.panel.hidden = false;
        var panelHeight = this.panel.offsetHeight || 280;
        this.panel.style.visibility = '';

        var gap = 4;
        var spaceBelow = window.innerHeight - rect.bottom - gap;
        var spaceAbove = rect.top - gap;
        var top;

        if (panelHeight <= spaceBelow || spaceBelow >= spaceAbove) {
            top = rect.bottom + gap;
        } else {
            top = Math.max(8, rect.top - panelHeight - gap);
        }

        this.panel.style.top = top + 'px';
        this.panel.style.maxHeight = Math.min(320, Math.max(spaceBelow, spaceAbove) - 8) + 'px';
    };

    CascadingLocationPicker.prototype.syncStepFromSelection = function () {
        if (this.mode === 'ph_birth_place') {
            if (!this.selection.provinceId) {
                this.stepIndex = 0;
                return;
            }
            if (!this.selection.municipalityId) {
                this.stepIndex = 1;
                return;
            }
            this.stepIndex = 2;
            return;
        }

        if (this.mode === 'ph_marriage_place') {
            if (!this.selection.country) {
                this.stepIndex = 0;
                return;
            }
            if (this.selection.countryCode !== 'PH') {
                this.stepIndex = 0;
                return;
            }
            if (!this.selection.provinceId) {
                this.stepIndex = 1;
                return;
            }
            this.stepIndex = 2;
            return;
        }

        if (!this.selection.country) {
            this.stepIndex = 0;
            return;
        }
        if (this.selection.countryCode !== 'PH') {
            this.stepIndex = 0;
            return;
        }
        if (!this.selection.provinceId) {
            this.stepIndex = 1;
            return;
        }
        if (!this.selection.municipalityId) {
            this.stepIndex = 2;
            return;
        }
        this.stepIndex = 3;
    };

    CascadingLocationPicker.prototype.tryResolveId = function (step, items) {
        var name = '';
        var idField = '';
        if (step === 'province') {
            name = this.selection.province;
            idField = 'provinceId';
        } else if (step === 'municipality') {
            name = this.selection.municipality;
            idField = 'municipalityId';
        } else if (step === 'barangay') {
            name = this.selection.barangay;
            idField = 'barangayId';
        } else {
            return false;
        }
        if (!name || this.selection[idField]) {
            return false;
        }

        var target = normalizeText(name);
        var match = items.find(function (item) {
            return normalizeText(item.name) === target
                || (step === 'barangay' && normalizeText(stripBarangayPrefix(item.name)) === target);
        });
        if (!match) {
            return false;
        }

        this.selection[idField] = Number(match.id);
        return true;
    };

    CascadingLocationPicker.prototype.toggle = function (show) {
        this.open = !!show;
        this.input.setAttribute('aria-expanded', this.open ? 'true' : 'false');

        if (!this.open) {
            this.panel.hidden = true;
            this.unbindPanelPositionListeners();
            if (this.panel.parentNode === document.body) {
                this.root.appendChild(this.panel);
            }
            return;
        }

        document.body.appendChild(this.panel);
        this.panel.hidden = false;
        this.searchEl.value = '';
        this.loadError = '';
        this.syncStepFromSelection();
        this.loadStep(this.stepIndex);
        this.bindPanelPositionListeners();
        this.positionPanel();

        if (this.currentStep() !== 'country') {
            this.searchEl.focus();
        }
    };

    CascadingLocationPicker.prototype.currentStep = function () {
        return this.steps[this.stepIndex] || this.steps[0] || 'country';
    };

    CascadingLocationPicker.prototype.updateSearchVisibility = function () {
        var step = this.currentStep();
        var showSearch = step !== 'country';
        this.searchEl.hidden = !showSearch;
        this.panel.classList.toggle('is-country-step', step === 'country');
    };

    CascadingLocationPicker.prototype.notifyInputChanged = function () {
        this.input.dispatchEvent(new Event('input', { bubbles: true }));
    };

    CascadingLocationPicker.prototype.updateInput = function () {
        this.input.value = partialValue(this.selection, this.mode);
        this.notifyInputChanged();
    };

    CascadingLocationPicker.prototype.resetFromStep = function (index) {
        this.stepIndex = index;
        var provinceIndex = this.mode === 'ph_birth_place' ? 0 : 1;
        var municipalityIndex = this.mode === 'ph_birth_place' ? 1 : 2;
        var barangayIndex = this.mode === 'ph_birth_place' ? 2 : 3;

        if (index <= provinceIndex) {
            this.selection.provinceId = 0;
            this.selection.province = '';
        }
        if (index <= municipalityIndex) {
            this.selection.municipalityId = 0;
            this.selection.municipality = '';
        }
        if (index <= barangayIndex) {
            this.selection.barangayId = 0;
            this.selection.barangay = '';
        }
    };

    CascadingLocationPicker.prototype.loadStep = function (index) {
        var self = this;
        var step = this.steps[index] || this.steps[0] || 'country';
        this.stepIndex = index;
        this.stepEl.textContent = 'Select ' + (STEP_LABELS[step] || step);
        this.updateSearchVisibility();
        this.loading = true;
        this.loadError = '';
        this.renderList();

        var promise;
        if (step === 'country') {
            promise = fetchItems(this.apiUrl, { level: 'countries' }).then(function (items) {
                return items.map(function (item) {
                    return { id: item.code, name: item.name, code: item.code };
                });
            });
        } else if (step === 'province') {
            if (this.selection.countryCode !== 'PH') {
                this.items = [];
                this.loading = false;
                this.renderList();
                this.positionPanel();
                return;
            }
            promise = fetchItems(this.apiUrl, { level: 'provinces', country: this.selection.countryCode }).then(function (items) {
                return items.map(function (item) {
                    return { id: item.id, name: item.name };
                });
            });
        } else if (step === 'municipality') {
            promise = fetchItems(this.apiUrl, { level: 'municipalities', province_id: String(this.selection.provinceId) }).then(function (items) {
                return items.map(function (item) {
                    return { id: item.id, name: item.name };
                });
            });
        } else {
            promise = fetchItems(this.apiUrl, { level: 'barangays', municipality_id: String(this.selection.municipalityId) }).then(function (items) {
                return items.map(function (item) {
                    return { id: item.id, name: item.name };
                });
            });
        }

        promise.then(function (items) {
            var resolved = self.tryResolveId(step, items);
            self.items = items;
            self.loading = false;
            self.renderList();
            self.positionPanel();

            if (resolved) {
                if (step === 'province' && self.selection.municipality && !self.selection.municipalityId) {
                    self.loadStep(self.steps.indexOf('municipality'));
                } else if (
                    step === 'municipality'
                    && self.selection.barangay
                    && !self.selection.barangayId
                    && self.steps.indexOf('barangay') !== -1
                ) {
                    self.loadStep(self.steps.indexOf('barangay'));
                }
            }
        }).catch(function (err) {
            self.items = [];
            self.loading = false;
            self.loadError = (err && err.message) || 'Location lookup failed.';
            self.renderList();
            self.positionPanel();
        });
    };

    CascadingLocationPicker.prototype.renderList = function () {
        var self = this;
        var query = normalizeText(this.searchEl.value);
        var filtered = this.items.filter(function (item) {
            return query === '' || normalizeText(item.name).indexOf(query) !== -1;
        });

        this.listEl.innerHTML = '';
        if (this.loading) {
            if (window.AlcrosLoading && typeof window.AlcrosLoading.skeletonInto === 'function') {
                window.AlcrosLoading.skeletonInto(this.listEl, 'inline', 5);
            } else {
                this.listEl.textContent = 'Loading options…';
            }
            return;
        }
        if (window.AlcrosLoading && typeof window.AlcrosLoading.clearSkeletonHost === 'function') {
            window.AlcrosLoading.clearSkeletonHost(this.listEl);
        }
        if (!filtered.length) {
            if (this.loadError) {
                this.listEl.textContent = this.loadError;
            } else {
                this.listEl.textContent = query ? 'No matches found.' : 'No options available.';
            }
            return;
        }

        filtered.forEach(function (item) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'cascading-location__option';
            btn.setAttribute('role', 'option');
            btn.textContent = item.name;
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                self.chooseItem(item);
            });
            self.listEl.appendChild(btn);
        });
    };

    CascadingLocationPicker.prototype.chooseItem = function (item) {
        var step = this.currentStep();
        if (step === 'country') {
            this.selection.countryCode = item.code || item.id;
            this.selection.country = item.name;
            this.resetFromStep(1);
            this.updateInput();
            if (this.selection.countryCode === 'PH') {
                this.searchEl.value = '';
                this.loadStep(1);
                this.searchEl.focus();
            } else {
                this.input.value = this.formatValue();
                this.notifyInputChanged();
                this.toggle(false);
            }
            return;
        }
        if (step === 'province') {
            this.selection.provinceId = Number(item.id);
            this.selection.province = item.name;
            this.selection.municipalityId = 0;
            this.selection.municipality = '';
            this.selection.barangayId = 0;
            this.selection.barangay = '';
            this.updateInput();
            this.searchEl.value = '';
            this.loadStep(this.steps.indexOf('municipality'));
            this.searchEl.focus();
            return;
        }
        if (step === 'municipality') {
            this.selection.municipalityId = Number(item.id);
            this.selection.municipality = item.name;
            this.selection.barangayId = 0;
            this.selection.barangay = '';
            this.input.value = this.formatValue();
            this.notifyInputChanged();
            if (this.mode === 'ph_marriage_place' || this.steps.indexOf('barangay') === -1) {
                this.toggle(false);
                return;
            }
            this.updateInput();
            this.searchEl.value = '';
            this.loadStep(this.steps.indexOf('barangay'));
            this.searchEl.focus();
            return;
        }

        this.selection.barangayId = Number(item.id);
        this.selection.barangay = item.name;
        this.input.value = this.formatValue();
        this.notifyInputChanged();
        this.toggle(false);
    };

    CascadingLocationPicker.prototype.seedFromValue = function (value) {
        var parts = String(value || '').split(',').map(function (part) {
            return part.trim();
        }).filter(Boolean);
        if (!parts.length) {
            return;
        }

        if (this.mode === 'ph_birth_place') {
            this.selection.countryCode = 'PH';
            this.selection.country = 'Philippines';

            if (normalizeText(parts[0]) === 'philippines' && parts.length >= 4) {
                this.selection.province = parts[1];
                this.selection.municipality = parts[2];
                this.selection.barangay = stripBarangayPrefix(parts[3]);
            } else if (parts.length >= 3) {
                this.selection.barangay = stripBarangayPrefix(parts[0]);
                this.selection.municipality = parts[1];
                this.selection.province = parts[2];
            } else if (parts.length === 2) {
                this.selection.municipality = parts[0];
                this.selection.province = parts[1];
            } else {
                this.selection.province = parts[0];
            }

            this.input.value = value;
            return;
        }

        if (this.mode === 'ph_marriage_place') {
            if (normalizeText(parts[0]) === 'philippines' && parts.length >= 3) {
                this.selection.country = parts[0];
                this.selection.countryCode = 'PH';
                this.selection.province = parts[1];
                this.selection.municipality = parts[2];
            } else if (parts.length >= 3) {
                this.selection.municipality = parts[0];
                this.selection.province = parts[1];
                this.selection.country = parts.slice(2).join(', ');
                this.selection.countryCode = normalizeText(this.selection.country) === 'philippines' ? 'PH' : 'OTHER';
            } else if (parts.length === 2) {
                this.selection.municipality = parts[0];
                this.selection.province = parts[1];
                this.selection.country = 'Philippines';
                this.selection.countryCode = 'PH';
            } else {
                this.selection.country = parts[0];
                this.selection.countryCode = normalizeText(parts[0]) === 'philippines' ? 'PH' : 'OTHER';
            }

            this.input.value = value;
            return;
        }

        if (this.mode === 'ph_residence') {
            if (normalizeText(parts[0]) === 'philippines' && parts.length >= 4) {
                this.selection.country = parts[0];
                this.selection.countryCode = 'PH';
                this.selection.province = parts[1];
                this.selection.municipality = parts[2];
                this.selection.barangay = stripBarangayPrefix(parts[3]);
            } else if (parts.length >= 4) {
                this.selection.barangay = stripBarangayPrefix(parts[0]);
                this.selection.municipality = parts[1];
                this.selection.province = parts[2];
                this.selection.country = parts.slice(3).join(', ');
                this.selection.countryCode = normalizeText(this.selection.country) === 'philippines' ? 'PH' : 'OTHER';
            } else if (parts.length === 3) {
                this.selection.barangay = stripBarangayPrefix(parts[0]);
                this.selection.municipality = parts[1];
                this.selection.province = parts[2];
                this.selection.country = 'Philippines';
                this.selection.countryCode = 'PH';
            } else if (parts.length === 2) {
                this.selection.municipality = parts[0];
                this.selection.province = parts[1];
                this.selection.country = 'Philippines';
                this.selection.countryCode = 'PH';
            } else {
                this.selection.country = parts[0];
                this.selection.countryCode = normalizeText(parts[0]) === 'philippines' ? 'PH' : 'OTHER';
            }

            this.input.value = value;
            return;
        }

        this.selection.country = parts[0];
        this.selection.countryCode = normalizeText(parts[0]) === 'philippines' ? 'PH' : 'OTHER';
        if (parts[1]) this.selection.province = parts[1];
        if (parts[2]) this.selection.municipality = parts[2];
        if (parts[3]) this.selection.barangay = stripBarangayPrefix(parts[3]);
        this.input.value = value;
    };

    function initCascadingLocationFields(options) {
        var apiUrl = options && options.apiUrl;
        if (!apiUrl) {
            return;
        }

        document.querySelectorAll('.js-cascading-location').forEach(function (input) {
            if (input.dataset.cascadingLocationInit === '1') return;
            input.dataset.cascadingLocationInit = '1';
            new CascadingLocationPicker(input, {
                apiUrl: apiUrl,
                mode: input.dataset.locationMode || 'full'
            });
        });

        document.querySelectorAll('#birthFieldsPanel input[name="place"]').forEach(function (input) {
            if (input.dataset.cascadingLocationInit === '1') return;
            input.dataset.cascadingLocationInit = '1';
            new CascadingLocationPicker(input, { apiUrl: apiUrl, mode: 'ph_birth_place' });
        });

    }

    global.AlcrosCascadingLocation = {
        init: initCascadingLocationFields,
        formatValue: formatValueFull,
        formatValuePhBirth: formatValuePhBirth,
        formatValuePhResidence: formatValuePhResidence,
        formatValuePhMarriage: formatValuePhMarriage
    };
})(window);
