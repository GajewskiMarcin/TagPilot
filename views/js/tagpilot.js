/**
 * TagPilot - Admin JavaScript
 * Handles config save, test connection, resend, purge, JSON formatting
 */
(function () {
    'use strict';

    var TP = {
        t: {},

        init: function () {
            // Load translations from JSON script tag
            var tEl = document.getElementById('tp-translations');
            if (tEl) {
                try { this.t = JSON.parse(tEl.textContent) || {}; } catch (e) { this.t = {}; }
            }

            this.bindSaveConfig();
            this.bindTestConnection();
            this.bindResendOrder();
            this.bindPurgeLogs();
            this.bindFilterEvent();
            this.formatJsonBlocks();
            this.bindWizard();
        },

        // ── Translation helper ────────────────────────────────────
        tr: function (key, fallback, replacements) {
            var str = this.t[key] || fallback || key;
            if (replacements) {
                for (var k in replacements) {
                    str = str.replace(k, replacements[k]);
                }
            }
            return str;
        },

        // ── Ajax helper ──────────────────────────────────────────
        ajax: function (url, method, data) {
            return fetch(url, {
                method: method || 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: data ? JSON.stringify(data) : undefined
            }).then(function (r) { return r.json(); });
        },

        // ── Badge helper ─────────────────────────────────────────
        // Returns a detached <span class="tp-badge tp-badge--<kind>"> with text set via
        // textContent. Used instead of building badge markup as a string, because some of
        // these messages carry text from the GA4 API response.
        badge: function (text, kind) {
            var el = document.createElement('span');
            el.className = 'tp-badge tp-badge--' + kind;
            el.textContent = text;
            return el;
        },

        // Replace an element's contents with a single node, without parsing HTML.
        setContent: function (el, node) {
            if (!el) return;
            el.textContent = '';
            el.appendChild(node);
        },

        // ── Toast notifications ──────────────────────────────────
        toast: function (message, type) {
            var container = document.getElementById('tp-toasts');
            if (!container) return;

            var toast = document.createElement('div');
            toast.className = 'tp-toast tp-toast--' + (type || 'success');

            // Built as nodes rather than innerHTML: `message` is not always trusted. Callers
            // pass GA4 Measurement Protocol validation text and API error strings straight
            // through, so an error message containing markup used to be parsed as HTML here.
            var text = document.createElement('span');
            text.className = 'tp-toast-message';
            text.textContent = message;

            var close = document.createElement('button');
            close.className = 'tp-toast-close';
            close.textContent = '\u00D7';
            close.addEventListener('click', function () { toast.remove(); });

            toast.appendChild(text);
            toast.appendChild(close);
            container.appendChild(toast);

            setTimeout(function () { toast.remove(); }, 5000);
        },

        // ── Save configuration ───────────────────────────────────
        doSaveConfig: function (triggerBtn) {
            var self = this;
            var data = {};
            var wrap = document.querySelector('.tp-wrap');

            // Collect all inputs from entire page. Disabled controls are skipped: they are the
            // settings the module stores but never reads, shown greyed out, and there is no
            // point rewriting their values on every save.
            wrap.querySelectorAll('input[name]:not([disabled]), select[name]:not([disabled])').forEach(function (el) {
                if (el.type === 'checkbox') {
                    data[el.name] = el.checked ? '1' : '0';
                } else {
                    data[el.name] = el.value;
                }
            });

            var origText = triggerBtn.textContent;
            triggerBtn.disabled = true;
            triggerBtn.textContent = self.tr('saving', 'Saving...');

            self.ajax(self.getApiUrl('config'), 'POST', data)
                .then(function (result) {
                    if (result.success) {
                        self.toast(self.tr('configSaved', 'Configuration saved'), 'success');
                    } else {
                        self.toast(self.tr('error', 'Error') + ': ' + (result.error || self.tr('unknownError', 'Unknown error')), 'error');
                    }
                })
                .catch(function () {
                    self.toast(self.tr('networkError', 'Network error'), 'error');
                })
                .finally(function () {
                    triggerBtn.disabled = false;
                    triggerBtn.textContent = origText;
                });
        },

        bindSaveConfig: function () {
            var self = this;

            // Main save button
            var btn = document.getElementById('tp-save-config');
            if (btn) {
                btn.addEventListener('click', function () { self.doSaveConfig(btn); });
            }

            // Section save buttons
            document.querySelectorAll('.tp-save-section').forEach(function (sectionBtn) {
                sectionBtn.addEventListener('click', function () { self.doSaveConfig(sectionBtn); });
            });
        },

        // ── Test Measurement Protocol connection ─────────────────
        bindTestConnection: function () {
            var btn = document.getElementById('tp-test-connection') || document.getElementById('tp-test-mp');
            if (!btn) return;

            var self = this;
            btn.addEventListener('click', function () {
                btn.disabled = true;
                var resultEl = document.getElementById('tp-connection-result') || document.getElementById('tp-test-result');

                self.ajax(self.getApiUrl('test-connection'), 'POST')
                    .then(function (result) {
                        if (result.success) {
                            self.setContent(resultEl, self.badge(self.tr('validationOk', 'Validation OK — no errors'), 'success'));
                            self.toast(self.tr('mpConnectionSuccess', 'GA4 Measurement Protocol connection successful'), 'success');
                        } else {
                            var msg = result.error || self.tr('validationErrors', 'Validation errors');
                            if (result.validationMessages && result.validationMessages.length) {
                                msg = result.validationMessages.map(function(m) { return m.description; }).join(', ');
                            }
                            self.setContent(resultEl, self.badge(self.tr('failed', 'Failed') + ': ' + msg, 'danger'));
                            self.toast(self.tr('connectionFailed', 'Connection failed') + ': ' + msg, 'error');
                        }
                    })
                    .catch(function () {
                        self.setContent(resultEl, self.badge(self.tr('networkError', 'Network error'), 'danger'));
                    })
                    .finally(function () {
                        btn.disabled = false;
                    });
            });
        },

        // ── Resend order ─────────────────────────────────────────
        bindResendOrder: function () {
            var btn = document.getElementById('tp-resend-order');
            if (!btn) return;

            var self = this;
            btn.addEventListener('click', function () {
                var id = btn.getAttribute('data-id');
                if (!confirm(self.tr('confirmResend', 'Are you sure you want to re-send this order to GA4?'))) return;

                btn.disabled = true;
                btn.textContent = self.tr('sending', 'Sending...');

                self.ajax(self.getApiUrl('resend-order/' + id), 'POST')
                    .then(function (result) {
                        if (result.success) {
                            self.toast(self.tr('orderResent', 'Order re-sent to GA4 successfully'), 'success');
                            btn.textContent = self.tr('resent', 'Re-sent!');
                        } else {
                            self.toast(self.tr('failed', 'Failed') + ': ' + (result.error || self.tr('unknownError', 'Unknown error')), 'error');
                            btn.textContent = self.tr('resendToGa4', 'Re-send to GA4');
                            btn.disabled = false;
                        }
                    })
                    .catch(function () {
                        self.toast(self.tr('networkError', 'Network error'), 'error');
                        btn.textContent = self.tr('resendToGa4', 'Re-send to GA4');
                        btn.disabled = false;
                    });
            });
        },

        // ── Purge logs ───────────────────────────────────────────
        bindPurgeLogs: function () {
            var self = this;

            function purge(btn, all, confirmMsg) {
                if (!confirm(confirmMsg)) return;
                btn.disabled = true;
                self.ajax(self.getApiUrl('purge-logs'), 'POST', all ? { all: true } : {})
                    .then(function (result) {
                        if (result.success) {
                            var msg = self.tr('logsPurgedDetails', 'Deleted %deleted% logs, %remaining% remaining.')
                                .replace('%deleted%', result.deleted || 0)
                                .replace('%remaining%', result.remaining || 0);
                            self.toast(msg, 'success');
                            setTimeout(function () { window.location.reload(); }, 1500);
                        }
                    })
                    .catch(function () {
                        self.toast(self.tr('failedPurgeLogs', 'Failed to purge logs'), 'error');
                    })
                    .finally(function () {
                        btn.disabled = false;
                    });
            }

            var oldBtn = document.getElementById('tp-purge-logs');
            if (oldBtn) {
                oldBtn.addEventListener('click', function () {
                    purge(oldBtn, false, self.tr('confirmPurge', 'Purge old event logs? This cannot be undone.'));
                });
            }

            var allBtn = document.getElementById('tp-purge-all-logs');
            if (allBtn) {
                allBtn.addEventListener('click', function () {
                    purge(allBtn, true, self.tr('confirmPurgeAll', 'Delete ALL event logs? This cannot be undone.'));
                });
            }
        },

        // ── Event filter (datalayer log page) ────────────────────
        bindFilterEvent: function () {
            var select = document.getElementById('tp-filter-event');
            if (!select) return;

            select.addEventListener('change', function () {
                var url = new URL(window.location.href);
                if (select.value) {
                    url.searchParams.set('event', select.value);
                } else {
                    url.searchParams.delete('event');
                }
                url.searchParams.delete('p');
                window.location.href = url.toString();
            });
        },

        // ── JSON formatting ──────────────────────────────────────
        formatJsonBlocks: function () {
            document.querySelectorAll('#tp-json-formatted').forEach(function (el) {
                try {
                    var raw = el.textContent.trim();
                    var parsed = JSON.parse(raw);
                    el.innerHTML = TP.syntaxHighlight(JSON.stringify(parsed, null, 2));
                } catch (e) {
                    // Leave as-is
                }
            });
        },

        syntaxHighlight: function (json) {
            json = json.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            return json.replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, function (match) {
                var cls = 'tp-json-number';
                if (/^"/.test(match)) {
                    if (/:$/.test(match)) {
                        cls = 'tp-json-key';
                        match = match.replace(/:$/, '') + ':';
                    } else {
                        cls = 'tp-json-string';
                    }
                } else if (/true|false/.test(match)) {
                    cls = 'tp-json-boolean';
                } else if (/null/.test(match)) {
                    cls = 'tp-json-null';
                }
                return '<span class="' + cls + '">' + match + '</span>';
            });
        },

        // ── GTM Wizard ──────────────────────────────────────────
        bindWizard: function () {
            var self = this;

            // Save OAuth credentials
            var saveCredsBtn = document.getElementById('tp-save-oauth-creds');
            if (saveCredsBtn) {
                saveCredsBtn.addEventListener('click', function () {
                    var clientId = document.getElementById('tp-oauth-client-id').value.trim();
                    var clientSecret = document.getElementById('tp-oauth-client-secret').value.trim();

                    if (!clientId || !clientSecret) {
                        self.toast(self.tr('enterCredentials', 'Please enter both Client ID and Client Secret'), 'error');
                        return;
                    }

                    saveCredsBtn.disabled = true;
                    saveCredsBtn.textContent = self.tr('saving', 'Saving...');

                    self.ajax(self.getApiUrl('oauth/credentials'), 'POST', {
                        client_id: clientId,
                        client_secret: clientSecret
                    }).then(function (result) {
                        if (result.success) {
                            self.toast(self.tr('credentialsSaved', 'Credentials saved! Reloading...'), 'success');
                            setTimeout(function () { window.location.reload(); }, 1000);
                        } else {
                            self.toast(self.tr('error', 'Error') + ': ' + (result.error || self.tr('unknownError', 'Unknown error')), 'error');
                        }
                    }).catch(function () {
                        self.toast(self.tr('networkError', 'Network error'), 'error');
                    }).finally(function () {
                        saveCredsBtn.disabled = false;
                        saveCredsBtn.textContent = self.tr('saveCredentials', 'Save credentials');
                    });
                });
            }

            // Connect with Google
            var connectBtn = document.getElementById('tp-connect-google');
            if (connectBtn) {
                connectBtn.addEventListener('click', function () {
                    connectBtn.disabled = true;
                    self.ajax(self.getApiUrl('oauth/start'), 'POST')
                        .then(function (result) {
                            if (result.success && result.url) {
                                window.location.href = result.url;
                            } else {
                                self.toast(self.tr('error', 'Error') + ': ' + (result.error || self.tr('couldNotStartOAuth', 'Could not start OAuth')), 'error');
                                connectBtn.disabled = false;
                            }
                        })
                        .catch(function () {
                            self.toast(self.tr('networkError', 'Network error'), 'error');
                            connectBtn.disabled = false;
                        });
                });
            }

            // Disconnect Google
            var disconnectBtn = document.getElementById('tp-disconnect-google');
            if (disconnectBtn) {
                disconnectBtn.addEventListener('click', function () {
                    var warning = self.tr('confirmDisconnectFull',
                        'Disconnect the Google account?\n\n'
                        + 'This will revoke TagPilot\'s access at Google and delete the OAuth client ID, '
                        + 'client secret and both tokens from this shop. Nothing about your GTM container '
                        + 'or GA4 property changes.\n\n'
                        + 'Tracking keeps working: the GTM container ID, GA4 Measurement ID and API secret '
                        + 'are kept. You only need to reconnect if you want to run the GTM auto-configurator again.');

                    if (!confirm(warning)) return;

                    self.ajax(self.getApiUrl('gtm/disconnect'), 'POST')
                        .then(function (result) {
                            if (!result.success) return;

                            // The local wipe always happens; the revoke can fail if Google is
                            // unreachable. Say which one it was instead of a blanket success.
                            if (result.revoked || !result.hadToken) {
                                self.toast(self.tr('disconnectedRevoked',
                                    'Disconnected. Access revoked at Google and all credentials deleted.'), 'success');
                            } else {
                                self.toast(self.tr('disconnectedNotRevoked',
                                    'Credentials deleted from this shop, but Google could not be reached to revoke '
                                    + 'access. Please remove it manually at myaccount.google.com/permissions'), 'error');
                            }

                            setTimeout(function () { window.location.reload(); }, 2500);
                        });
                });
            }

            // GTM Account selection -> load containers
            var accountSelect = document.getElementById('tp-gtm-account');
            var containerSelect = document.getElementById('tp-gtm-container');
            if (accountSelect && containerSelect) {
                accountSelect.addEventListener('change', function () {
                    var accountId = accountSelect.value;
                    containerSelect.innerHTML = '<option value="">' + self.tr('loading', 'Loading...') + '</option>';
                    containerSelect.disabled = true;

                    if (!accountId) {
                        containerSelect.innerHTML = '<option value="">' + self.tr('selectContainer', '-- Select container --') + '</option>';
                        return;
                    }

                    self.ajax(self.getApiUrl('gtm/containers/' + accountId), 'GET')
                        .then(function (result) {
                            containerSelect.innerHTML = '<option value="">' + self.tr('selectContainer', '-- Select container --') + '</option>';
                            if (result.success && result.containers) {
                                var savedId = containerSelect.getAttribute('data-selected') || '';
                                result.containers.forEach(function (c) {
                                    var opt = document.createElement('option');
                                    opt.value = c.containerId;
                                    opt.textContent = c.name + ' (' + (c.publicId || '') + ')';
                                    opt.setAttribute('data-public-id', c.publicId || '');
                                    opt.setAttribute('data-name', c.name || '');
                                    if (savedId && c.containerId === savedId) {
                                        opt.selected = true;
                                    }
                                    containerSelect.appendChild(opt);
                                });
                            } else {
                                self.toast(self.tr('errorLoadingContainers', 'Error loading containers') + ': ' + (result.error || ''), 'error');
                            }
                            containerSelect.disabled = false;
                        })
                        .catch(function () {
                            containerSelect.innerHTML = '<option value="">' + self.tr('errorLoading', 'Error loading') + '</option>';
                            containerSelect.disabled = false;
                        });
                });

                // Auto-load containers if account is pre-selected
                if (accountSelect.value) {
                    accountSelect.dispatchEvent(new Event('change'));
                }
            }

            // Activate GTM (auto-configure)
            var activateBtn = document.getElementById('tp-activate-gtm');
            if (activateBtn) {
                activateBtn.addEventListener('click', function () {
                    var accountId = document.getElementById('tp-gtm-account') ? document.getElementById('tp-gtm-account').value : '';
                    var containerEl = document.getElementById('tp-gtm-container');
                    var containerId = containerEl ? containerEl.value : '';
                    var measurementId = document.getElementById('tp-ga4-measurement-id') ? document.getElementById('tp-ga4-measurement-id').value.trim() : '';

                    if (!accountId || !containerId || !measurementId) {
                        self.toast(self.tr('selectAccountContainer', 'Please select an account, container, and enter GA4 Measurement ID'), 'error');
                        return;
                    }

                    var selectedOption = containerEl.options[containerEl.selectedIndex];
                    var containerPublicId = selectedOption.getAttribute('data-public-id') || '';
                    var containerName = selectedOption.getAttribute('data-name') || '';

                    var adsConversionId = document.getElementById('tp-ads-conversion-id') ? document.getElementById('tp-ads-conversion-id').value.trim() : '';
                    var adsConversionLabel = document.getElementById('tp-ads-conversion-label') ? document.getElementById('tp-ads-conversion-label').value.trim() : '';

                    activateBtn.disabled = true;
                    activateBtn.textContent = self.tr('configuringGtm', 'Configuring GTM...');

                    // Show log
                    var logEl = document.getElementById('tp-wizard-log');
                    var logContent = document.getElementById('tp-wizard-log-content');
                    if (logEl) logEl.style.display = 'block';
                    if (logContent) logContent.textContent = self.tr('startingConfig', 'Starting auto-configuration...') + '\n';

                    self.ajax(self.getApiUrl('gtm/configure'), 'POST', {
                        account_id: accountId,
                        container_id: containerId,
                        container_public_id: containerPublicId,
                        container_name: containerName,
                        measurement_id: measurementId,
                        ads_conversion_id: adsConversionId,
                        ads_conversion_label: adsConversionLabel
                    }).then(function (result) {
                        if (result.success) {
                            var summary = self.tr('configComplete', 'Configuration complete!') + '\n\n';
                            summary += 'Tags: ' + (result.created.tags || []).join(', ') + '\n';
                            summary += 'Triggers: ' + (result.created.triggers || []).join(', ') + '\n';
                            summary += 'Variables: ' + (result.created.variables || []).join(', ') + '\n';

                            if (result.errors && result.errors.length) {
                                summary += '\n' + self.tr('warnings', 'Warnings') + ':\n' + result.errors.join('\n');
                            }

                            if (logContent) logContent.textContent = summary;
                            self.toast(self.tr('gtmConfigured', 'GTM auto-configured! Click "Publish" to go live.'), 'success');

                            setTimeout(function () { window.location.reload(); }, 2000);
                        } else {
                            if (logContent) logContent.textContent = self.tr('error', 'Error') + ': ' + (result.error || self.tr('unknownError', 'Unknown error'));
                            self.toast(self.tr('configFailed', 'Configuration failed') + ': ' + (result.error || ''), 'error');
                            activateBtn.disabled = false;
                            activateBtn.textContent = self.tr('activateTracking', 'Activate tracking');
                        }
                    }).catch(function () {
                        if (logContent) logContent.textContent = self.tr('networkError', 'Network error');
                        self.toast(self.tr('networkError', 'Network error'), 'error');
                        activateBtn.disabled = false;
                        activateBtn.textContent = self.tr('activateTracking', 'Activate tracking');
                    });
                });
            }

            // Publish
            var publishBtn = document.getElementById('tp-publish-gtm');
            if (publishBtn) {
                publishBtn.addEventListener('click', function () {
                    if (!confirm(self.tr('confirmPublish', 'Publish changes to your GTM container? This will make them live.'))) return;
                    publishBtn.disabled = true;
                    publishBtn.textContent = self.tr('publishing', 'Publishing...');

                    self.ajax(self.getApiUrl('gtm/publish'), 'POST')
                        .then(function (result) {
                            if (result.success) {
                                self.toast(self.tr('published', 'Published to GTM!') + ' ' + self.tr('version', 'Version') + ': ' + (result.version || ''), 'success');
                                setTimeout(function () { window.location.reload(); }, 1500);
                            } else {
                                self.toast(self.tr('publishFailed', 'Publish failed') + ': ' + (result.error || ''), 'error');
                                publishBtn.disabled = false;
                                publishBtn.textContent = self.tr('publishToGtm', 'Publish to GTM');
                            }
                        })
                        .catch(function () {
                            self.toast(self.tr('networkError', 'Network error'), 'error');
                            publishBtn.disabled = false;
                            publishBtn.textContent = self.tr('publishToGtm', 'Publish to GTM');
                        });
                });
            }

            // Reconfigure
            var reconfigBtn = document.getElementById('tp-reconfigure-gtm');
            if (reconfigBtn) {
                reconfigBtn.addEventListener('click', function () {
                    if (!confirm(self.tr('confirmReconfigure', 'Reconfigure will create new tags/triggers in GTM. Continue?'))) return;
                    self.ajax(self.getApiUrl('config'), 'POST', { GTM_CONFIGURED: '0' })
                        .then(function () {
                            window.location.reload();
                        });
                });
            }

            // Check for OAuth callback params
            var params = new URLSearchParams(window.location.search);
            if (params.get('oauth_success')) {
                self.toast(self.tr('googleConnected', 'Google account connected successfully!'), 'success');
                // Clean URL
                var cleanUrl = window.location.pathname;
                window.history.replaceState({}, '', cleanUrl);
            }
            if (params.get('oauth_error')) {
                self.toast(self.tr('oauthError', 'OAuth error') + ': ' + params.get('oauth_error'), 'error');
                var cleanUrl2 = window.location.pathname;
                window.history.replaceState({}, '', cleanUrl2);
            }
        },

        // ── URL builder ──────────────────────────────────────────
        getApiUrl: function (endpoint) {
            // Use Symfony routes - find the base admin URL from current page
            var currentUrl = window.location.pathname;
            var adminMatch = currentUrl.match(/(.+\/tagpilot)/);
            var adminBase = adminMatch ? adminMatch[1] : currentUrl.replace(/\/[^\/]*$/, '/tagpilot');

            var url = adminBase + '/api/' + endpoint;

            // Append CSRF token from current page URL (required by PrestaShop 9)
            var params = new URLSearchParams(window.location.search);
            var token = params.get('_token');
            if (token) {
                url += (url.indexOf('?') === -1 ? '?' : '&') + '_token=' + encodeURIComponent(token);
            }

            return url;
        }
    };

    // Init
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { TP.init(); });
    } else {
        TP.init();
    }

    window.TagPilot = TP;
})();
