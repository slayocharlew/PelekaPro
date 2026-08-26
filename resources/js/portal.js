function initializePortalNavigation(root) {
    const toggle = root.querySelector('[data-portal-nav-toggle]');
    const navigation = root.querySelector('[data-portal-nav]');

    if (!toggle || !navigation) {
        return;
    }

    toggle.addEventListener('click', () => {
        const expanded = toggle.getAttribute('aria-expanded') === 'true';
        toggle.setAttribute('aria-expanded', String(!expanded));
        navigation.classList.toggle('is-open', !expanded);
    });
}

function initializeSubmittingForms(root) {
    root.querySelectorAll('[data-confirm]').forEach((form) => {
        if (form.dataset.confirmReady === 'true') {
            return;
        }

        form.dataset.confirmReady = 'true';
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
    });

    root.querySelectorAll('[data-submitting-form]').forEach((form) => {
        if (form.dataset.submittingReady === 'true') {
            return;
        }

        form.dataset.submittingReady = 'true';
        form.addEventListener('submit', (event) => {
            if (event.defaultPrevented) {
                return;
            }

            const button = form.querySelector('button[type="submit"]');

            if (!button) {
                return;
            }

            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            form.setAttribute('aria-busy', 'true');
            button.dataset.originalSubmitLabel ||= button.textContent;
            button.textContent = button.dataset.submitLabel || 'Submitting…';
        });
    });
}

function restoreSubmittingForm(form) {
    form.removeAttribute('aria-busy');
    form.querySelectorAll('button[type="submit"]').forEach((button) => {
        button.disabled = false;
        button.removeAttribute('aria-busy');

        if (button.dataset.originalSubmitLabel) {
            button.textContent = button.dataset.originalSubmitLabel;
        }
    });
}

function initializeDeliveryItems(root) {
    const container = root.querySelector('[data-delivery-items]');
    const template = root.querySelector('[data-delivery-item-template]');
    const addButton = root.querySelector('[data-add-delivery-item]');

    if (!container || !template || !addButton) {
        return;
    }

    const refresh = () => {
        const items = [...container.querySelectorAll('[data-delivery-item]')];

        items.forEach((item, index) => {
            const number = item.querySelector('[data-item-number]');
            const remove = item.querySelector('[data-remove-delivery-item]');

            if (number) {
                number.textContent = String(index + 1);
            }

            if (remove) {
                remove.disabled = items.length === 1;
                remove.setAttribute('aria-label', `Remove item ${index + 1}`);
            }
        });
    };

    container.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-remove-delivery-item]');

        if (!remove || container.querySelectorAll('[data-delivery-item]').length <= 1) {
            return;
        }

        remove.closest('[data-delivery-item]')?.remove();
        refresh();
    });

    addButton.addEventListener('click', () => {
        const index = `${Date.now()}${container.children.length}`;
        const wrapper = document.createElement('div');
        wrapper.innerHTML = template.innerHTML.replaceAll('__INDEX__', index).trim();
        const item = wrapper.firstElementChild;

        if (item) {
            container.append(item);
            refresh();
            item.querySelector('input')?.focus();
        }
    });

    refresh();
}

function initializeDeliverySelectors(root) {
    const businessSelect = root.querySelector('[data-business-select]');
    const customerSelect = root.querySelector('[data-customer-select]');
    const addressSelect = root.querySelector('[data-address-select]');

    const filterOptions = () => {
        const businessId = businessSelect?.value || null;
        const customerId = customerSelect?.value || null;

        root.querySelectorAll('[data-business-option-list]').forEach((select) => {
            [...select.options].forEach((option) => {
                if (!option.dataset.businessId || !businessId) {
                    return;
                }

                const allowed = option.dataset.businessId === businessId;
                option.hidden = !allowed;
                option.disabled = !allowed;

                if (!allowed && option.selected) {
                    select.value = '';
                }
            });
        });

        if (addressSelect) {
            [...addressSelect.options].forEach((option) => {
                if (!option.dataset.customerId) {
                    return;
                }

                const businessAllowed = !businessId || option.dataset.businessId === businessId;
                const customerAllowed = !customerId || option.dataset.customerId === customerId;
                const allowed = businessAllowed && customerAllowed;
                option.hidden = !allowed;
                option.disabled = !allowed;

                if (!allowed && option.selected) {
                    addressSelect.value = '';
                }
            });
        }
    };

    businessSelect?.addEventListener('change', filterOptions);
    customerSelect?.addEventListener('change', filterOptions);
    filterOptions();
}

function initializeBranchPickupDefaults(root) {
    root.querySelectorAll('[data-branch-pickup-select]').forEach((select) => {
        const form = select.closest('form') || root;
        const name = form.querySelector('[data-pickup-name-field]');
        const phone = form.querySelector('[data-pickup-phone-field]');
        const address = form.querySelector('[data-pickup-address-field]');
        const latitude = form.querySelector('[data-pickup-latitude-field]');
        const longitude = form.querySelector('[data-pickup-longitude-field]');
        const status = form.querySelector('[data-branch-pickup-status]');
        let hadSelectedBranch = false;

        const setReadonly = (field, readonly) => {
            if (!field) return;

            field.readOnly = readonly;
            field.toggleAttribute('aria-readonly', readonly);
        };

        const refresh = () => {
            const option = select.selectedOptions[0];
            const hasSelectedBranch = Boolean(option?.value);

            if (hasSelectedBranch) {
                if (name) name.value = option.dataset.pickupName || '';
                if (phone) phone.value = option.dataset.pickupPhone || '';
                if (address) address.value = option.dataset.pickupAddress || '';
                if (latitude) latitude.value = option.dataset.pickupLatitude || '';
                if (longitude) longitude.value = option.dataset.pickupLongitude || '';
                if (status) status.textContent = `Pickup loaded from ${option.textContent.trim()}.`;
            } else {
                if (hadSelectedBranch) {
                    [name, phone, address, latitude, longitude].forEach((field) => {
                        if (field) field.value = '';
                    });
                }

                if (status) status.textContent = 'No branch selected. Pickup coordinates will remain unavailable.';
            }

            [name, phone, address].forEach((field) => setReadonly(field, hasSelectedBranch));
            hadSelectedBranch = hasSelectedBranch;
        };

        select.addEventListener('change', refresh);
        form.querySelector('[data-business-select]')?.addEventListener('change', refresh);
        refresh();
    });
}

async function copyText(text) {
    if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(text);

        return;
    }

    const temporary = document.createElement('textarea');
    temporary.value = text;
    temporary.setAttribute('readonly', '');
    temporary.style.position = 'fixed';
    temporary.style.opacity = '0';
    document.body.append(temporary);
    temporary.select();
    document.execCommand('copy');
    temporary.remove();
}

function initializeTrackingLink(root) {
    const status = root.querySelector('[data-copy-status]');

    root.querySelectorAll('[data-copy-target]').forEach((button) => {
        button.addEventListener('click', async () => {
            const input = document.getElementById(button.dataset.copyTarget);

            if (!input) {
                return;
            }

            try {
                await copyText(input.value);
                if (status) status.textContent = 'Tracking link copied.';
            } catch {
                if (status) status.textContent = 'Copy was unavailable. Select the link and copy it manually.';
                input.focus();
                input.select();
            }
        });
    });

    root.querySelectorAll('[data-share-url]').forEach((button) => {
        button.addEventListener('click', async () => {
            const share = {
                title: button.dataset.shareTitle || 'PelekaPro delivery tracking',
                url: button.dataset.shareUrl,
            };

            try {
                if (navigator.share) {
                    await navigator.share(share);
                    if (status) status.textContent = 'Tracking link shared.';
                } else {
                    await copyText(share.url);
                    if (status) status.textContent = 'Sharing is unavailable, so the tracking link was copied.';
                }
            } catch (error) {
                if (error?.name !== 'AbortError' && status) {
                    status.textContent = 'The tracking link could not be shared.';
                }
            }
        });
    });
}

function initializeDialogs(root) {
    root.querySelectorAll('[data-dialog-open]').forEach((button) => {
        button.addEventListener('click', () => {
            document.getElementById(button.dataset.dialogOpen)?.showModal();
        });
    });

    root.querySelectorAll('[data-dialog-close]').forEach((button) => {
        button.addEventListener('click', () => button.closest('dialog')?.close());
    });
}

function initializeCustomerResolution(root) {
    const resolution = root.querySelector('[data-customer-resolution]');
    const existingField = root.querySelector('[data-existing-customer-field]');
    const customer = existingField?.querySelector('select');

    if (!resolution || !existingField || !customer) {
        return;
    }

    const refresh = () => {
        const usesExistingCustomer = resolution.value === 'existing';
        existingField.hidden = !usesExistingCustomer;
        customer.disabled = !usesExistingCustomer;
        customer.required = usesExistingCustomer;

        if (!usesExistingCustomer) {
            customer.value = '';
        }
    };

    resolution.addEventListener('change', refresh);
    refresh();
}

let deliveryBrowserRequest;

function deliveryFilterUrl(form) {
    const url = new URL(form.action, window.location.origin);

    for (const [key, value] of new FormData(form).entries()) {
        if (typeof value === 'string' && value.trim() !== '') {
            url.searchParams.set(key, value.trim());
        }
    }

    return url;
}

async function loadDeliveryBrowser(root, requestedUrl, updateHistory = true) {
    const currentBrowser = root.querySelector('[data-delivery-browser]');

    if (!currentBrowser) {
        window.location.assign(requestedUrl);

        return;
    }

    deliveryBrowserRequest?.abort();
    deliveryBrowserRequest = new AbortController();
    currentBrowser.setAttribute('aria-busy', 'true');

    const activeField = currentBrowser.contains(document.activeElement)
        ? document.activeElement
        : null;
    const activeFieldName = activeField?.getAttribute('name');
    const selectionStart = activeField instanceof HTMLInputElement
        ? activeField.selectionStart
        : null;
    const selectionEnd = activeField instanceof HTMLInputElement
        ? activeField.selectionEnd
        : null;

    const currentStatus = currentBrowser.querySelector('[data-delivery-filter-status]');
    if (currentStatus) {
        currentStatus.textContent = 'Updating deliveries.';
    }

    try {
        const response = await fetch(requestedUrl, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                Accept: 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
                'X-PelekaPro-Partial': '1',
            },
            signal: deliveryBrowserRequest.signal,
        });

        if (!response.ok) {
            throw new Error('Delivery filters could not be loaded.');
        }

        const documentFragment = new DOMParser().parseFromString(await response.text(), 'text/html');
        const nextBrowser = documentFragment.querySelector('[data-delivery-browser]');

        if (!nextBrowser) {
            throw new Error('Delivery filter response was invalid.');
        }

        currentBrowser.replaceWith(nextBrowser);
        initializeSubmittingForms(nextBrowser);
        initializeDeliveryFilters(root);

        const nextForm = nextBrowser.querySelector('[data-delivery-filter-form]');
        const nextActiveField = activeFieldName
            ? nextForm?.elements.namedItem(activeFieldName)
            : null;
        if (nextActiveField instanceof HTMLElement) {
            nextActiveField.focus({ preventScroll: true });
            if (nextActiveField instanceof HTMLInputElement
                && selectionStart !== null
                && selectionEnd !== null
            ) {
                nextActiveField.setSelectionRange(selectionStart, selectionEnd);
            }
        }

        const nextStatus = nextBrowser.querySelector('[data-delivery-filter-status]');
        if (nextStatus) {
            nextStatus.textContent = 'Delivery list updated.';
        }

        if (updateHistory) {
            window.history.pushState({}, '', requestedUrl);
        }
    } catch (error) {
        if (error?.name === 'AbortError') {
            return;
        }

        window.location.assign(requestedUrl);
    }
}

function initializeDeliveryFilters(root) {
    const browser = root.querySelector('[data-delivery-browser]');
    const form = browser?.querySelector('[data-delivery-filter-form]');

    if (!browser || !form || browser.dataset.deliveryFiltersReady === 'true') {
        return;
    }

    browser.dataset.deliveryFiltersReady = 'true';
    let searchTimer;

    const applyForm = () => {
        window.clearTimeout(searchTimer);
        loadDeliveryBrowser(root, deliveryFilterUrl(form));
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        applyForm();
    });

    form.querySelectorAll('select').forEach((select) => {
        select.addEventListener('change', applyForm);
    });

    form.querySelector('input[type="search"]')?.addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(applyForm, 350);
    });

    browser.addEventListener('click', (event) => {
        const link = event.target.closest('[data-delivery-ajax-link], .portal-pagination a');

        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        event.preventDefault();
        loadDeliveryBrowser(root, link.href);
    });

}

let portalNavigationRequest = null;
let portalMutationInFlight = false;

export function isPortalDestination(destination, portalBase, currentOrigin) {
    try {
        const origin = currentOrigin
            || (typeof window !== 'undefined' ? window.location.origin : null);

        if (!origin) {
            return false;
        }

        const url = new URL(destination, origin);
        const base = new URL(portalBase, origin);
        const basePath = base.pathname.replace(/\/$/, '');

        return url.origin === origin
            && base.origin === origin
            && (url.pathname === basePath || url.pathname.startsWith(`${basePath}/`));
    } catch {
        return false;
    }
}

function portalCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

function setPortalBusy(root, busy, message = '') {
    const main = root.querySelector('[data-portal-main]');
    const progress = root.querySelector('[data-portal-progress]');
    const status = root.querySelector('[data-portal-navigation-status]');

    root.classList.toggle('is-navigating', busy);
    main?.setAttribute('aria-busy', String(busy));
    if (progress) progress.hidden = !busy;
    if (status) status.textContent = message;
}

function showPortalNavigationError(root, message) {
    const container = root.querySelector('[data-portal-main] .portal-container');

    if (!container) {
        return;
    }

    container.querySelector('[data-portal-navigation-error]')?.remove();
    const alert = document.createElement('div');
    alert.className = 'portal-alert portal-alert--error';
    alert.dataset.portalNavigationError = 'true';
    alert.setAttribute('role', 'alert');
    alert.textContent = message;
    container.prepend(alert);
    alert.scrollIntoView({ block: 'nearest' });
}

function templateText(documentFragment, selector) {
    const template = documentFragment.querySelector(selector);

    return template?.content?.textContent?.trim() || '';
}

function updatePortalNavigation(root, activeSection) {
    root.querySelectorAll('[data-portal-nav-section]').forEach((link) => {
        const active = link.dataset.portalNavSection === activeSection;
        link.classList.toggle('is-active', active);

        if (active) {
            link.setAttribute('aria-current', 'page');
        } else {
            link.removeAttribute('aria-current');
        }
    });

    root.querySelector('[data-portal-nav]')?.classList.remove('is-open');
    root.querySelector('[data-portal-nav-toggle]')?.setAttribute('aria-expanded', 'false');
}

function focusPortalContent(main, destination) {
    const hash = new URL(destination, window.location.href).hash;

    if (hash) {
        const target = document.getElementById(decodeURIComponent(hash.slice(1)));

        if (target) {
            target.scrollIntoView({ block: 'start' });
            target.focus?.({ preventScroll: true });

            return;
        }
    }

    window.scrollTo({ top: 0, left: 0, behavior: 'instant' });
    main.focus({ preventScroll: true });
}

function renderPortalResponse(root, html, destination) {
    const documentFragment = new DOMParser().parseFromString(html, 'text/html');
    const nextMain = documentFragment.querySelector('[data-portal-main]');
    const currentMain = root.querySelector('[data-portal-main]');

    if (!nextMain || !currentMain) {
        return false;
    }

    currentMain.replaceWith(nextMain);
    const title = templateText(documentFragment, '[data-portal-title]')
        || documentFragment.title.trim();
    if (title) document.title = title;
    updatePortalNavigation(
        root,
        templateText(documentFragment, '[data-portal-navigation-state]')
    );
    initializePortalContent(root);
    document.dispatchEvent(new CustomEvent('pelekapro:portal-rendered', {
        detail: { main: nextMain },
    }));
    focusPortalContent(nextMain, destination);

    return true;
}

function portalRequestHeaders() {
    const headers = {
        Accept: 'text/html',
        'X-Requested-With': 'XMLHttpRequest',
        'X-PelekaPro-Partial': '1',
    };
    const csrfToken = portalCsrfToken();

    if (csrfToken) {
        headers['X-CSRF-TOKEN'] = csrfToken;
    }

    return headers;
}

async function requestPortalNavigation(root, requestedUrl, options = {}) {
    const mutation = options.mutation === true;

    if (mutation && portalMutationInFlight) {
        if (options.form) restoreSubmittingForm(options.form);

        return;
    }

    if (mutation) {
        portalNavigationRequest?.abort();
        portalNavigationRequest = null;
    } else {
        portalNavigationRequest?.abort();
    }

    const controller = new AbortController();
    if (mutation) portalMutationInFlight = true;
    else portalNavigationRequest = controller;
    setPortalBusy(root, true, mutation ? 'Saving changes…' : 'Loading page…');

    try {
        const response = await fetch(requestedUrl, {
            method: options.method || 'GET',
            body: options.body || null,
            credentials: 'same-origin',
            cache: 'no-store',
            redirect: 'follow',
            headers: portalRequestHeaders(),
            signal: controller.signal,
        });
        const responseUrl = response.url || requestedUrl;

        if (!isPortalDestination(responseUrl, root.dataset.portalBase)) {
            window.location.assign(responseUrl);

            return;
        }

        const html = await response.text();
        if (!response.ok || !renderPortalResponse(root, html, requestedUrl)) {
            throw new Error('The portal response could not be displayed.');
        }

        const requested = new URL(requestedUrl, window.location.href);
        const destination = new URL(responseUrl, window.location.href);
        if (requested.hash
            && requested.pathname === destination.pathname
            && requested.search === destination.search
        ) {
            destination.hash = requested.hash;
        }

        if (options.history !== 'none' && destination.href !== window.location.href) {
            window.history.pushState({ pelekaproPortal: true }, '', destination);
        }

        setPortalBusy(root, false, 'Page updated.');
    } catch (error) {
        if (error?.name === 'AbortError') {
            return;
        }

        if (mutation) {
            showPortalNavigationError(
                root,
                'PelekaPro could not confirm that change. Check your connection, then refresh before trying again.'
            );
            if (options.form) restoreSubmittingForm(options.form);
            setPortalBusy(root, false, 'The change could not be confirmed.');
        } else {
            window.location.assign(requestedUrl);
        }
    } finally {
        if (mutation) portalMutationInFlight = false;
        else if (portalNavigationRequest === controller) portalNavigationRequest = null;
    }
}

function formNavigationRequest(form, submitter) {
    const method = (form.method || 'GET').toUpperCase();
    const body = typeof FormData === 'function'
        ? (submitter ? new FormData(form, submitter) : new FormData(form))
        : null;
    const url = new URL(form.action, window.location.href);

    if (method === 'GET' && body) {
        url.search = '';
        for (const [key, value] of body.entries()) {
            if (typeof value === 'string' && value.trim() !== '') {
                url.searchParams.append(key, value);
            }
        }

        return { method: 'GET', body: null, url };
    }

    return { method, body, url };
}

function initializePortalAjaxNavigation(root) {
    if (root.dataset.portalAjaxReady === 'true') {
        return;
    }

    root.dataset.portalAjaxReady = 'true';
    root.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');

        if (!link
            || event.defaultPrevented
            || event.button !== 0
            || event.metaKey
            || event.ctrlKey
            || event.shiftKey
            || event.altKey
            || link.target
            || link.hasAttribute('download')
            || link.hasAttribute('data-no-portal-ajax')
            || !isPortalDestination(link.href, root.dataset.portalBase)
        ) {
            return;
        }

        const destination = new URL(link.href, window.location.href);
        if (destination.pathname === window.location.pathname
            && destination.search === window.location.search
            && destination.hash
        ) {
            return;
        }

        event.preventDefault();
        requestPortalNavigation(root, destination);
    });

    root.addEventListener('submit', (event) => {
        const form = event.target;

        if (!(form instanceof HTMLFormElement)
            || event.defaultPrevented
            || form.hasAttribute('data-no-portal-ajax')
            || !isPortalDestination(form.action, root.dataset.portalBase)
        ) {
            return;
        }

        event.preventDefault();
        const request = formNavigationRequest(form, event.submitter);
        requestPortalNavigation(root, request.url, {
            method: request.method,
            body: request.body,
            mutation: request.method !== 'GET',
            form,
        });
    });

    window.addEventListener('popstate', () => {
        if (isPortalDestination(window.location.href, root.dataset.portalBase)) {
            requestPortalNavigation(root, window.location.href, { history: 'none' });
        }
    });
}

function initializePortalContent(root) {
    initializeSubmittingForms(root);
    initializeDeliveryItems(root);
    initializeDeliverySelectors(root);
    initializeBranchPickupDefaults(root);
    initializeTrackingLink(root);
    initializeDialogs(root);
    initializeCustomerResolution(root);
    initializeDeliveryFilters(root);
}

export function initializePortal() {
    const root = document.querySelector('[data-portal]');

    if (!root) {
        return;
    }

    initializePortalNavigation(root);
    initializePortalContent(root);
    initializePortalAjaxNavigation(root);
}
