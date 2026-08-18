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

export function initializePortal() {
    const root = document.querySelector('[data-portal]');

    if (!root) {
        return;
    }

    initializePortalNavigation(root);
    initializeSubmittingForms(root);
    initializeDeliveryItems(root);
    initializeDeliverySelectors(root);
    initializeBranchPickupDefaults(root);
    initializeTrackingLink(root);
    initializeDialogs(root);
    initializeCustomerResolution(root);
}
