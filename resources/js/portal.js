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
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
    });

    root.querySelectorAll('[data-submitting-form]').forEach((form) => {
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
            button.textContent = button.dataset.submitLabel || 'Submitting…';
        });
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

export function initializePortal() {
    const root = document.querySelector('[data-portal]');

    if (!root) {
        return;
    }

    initializePortalNavigation(root);
    initializeSubmittingForms(root);
    initializeDeliveryItems(root);
    initializeDeliverySelectors(root);
    initializeTrackingLink(root);
    initializeDialogs(root);
}
