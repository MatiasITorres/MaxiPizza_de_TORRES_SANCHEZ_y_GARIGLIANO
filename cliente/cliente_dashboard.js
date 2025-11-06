// Este script asume que las variables PRODUCT_DATA, MODIFICATIONS_DATA, cart,
// selectedOrderType, currentTheme, currentModProduct, y selectedModificationsQuantities
// ya fueron definidas en el HTML/PHP justo antes de cargar este archivo.

// Función para formatear números como moneda
const formatCurrency = (number) => { 
    return '$' + new Intl.NumberFormat('es-AR', { minimumFractionDigits: 2 }).format(number); 
}; 

// Función de Mensajes (Implementación simple)
function showStatusMessage(message, type) { 
    alert(`[${type.toUpperCase()}] ${message}`); 
}

// -----------------------------------------------------------
// LÓGICA DE CAMBIO DE TEMA
// -----------------------------------------------------------

/**
 * Aplica la clase de tema al body y guarda la preferencia.
 */
function setTheme(mode) {
    const body = document.body;
    const toggleButton = document.getElementById('theme-toggle');
    
    if (!toggleButton) return; 
    
    if (mode === 'dark') {
        body.classList.add('dark-mode');
        toggleButton.innerHTML = '<i class="fas fa-sun"></i>'; // Mostrar Sol para cambiar a Claro
        currentTheme = 'dark';
    } else {
        body.classList.remove('dark-mode');
        toggleButton.innerHTML = '<i class="fas fa-moon"></i>'; // Mostrar Luna para cambiar a Oscuro
        currentTheme = 'light';
    }
    localStorage.setItem('kiosco_theme', currentTheme);
}

/**
 * Alterna entre el modo claro y oscuro.
 */
function toggleTheme() {
    const newTheme = currentTheme === 'light' ? 'dark' : 'light';
    setTheme(newTheme);
}

// -----------------------------------------------------------
// LÓGICA DE CARRITO MÓVIL
// -----------------------------------------------------------

/**
 * Muestra/Oculta la sección del carrito en móviles.
 */
function toggleMobileCart() {
    // Solo aplica la lógica de toggle si estamos en móvil (ancho <= 768px)
    if (window.innerWidth > 768) return;

    const cartSection = document.querySelector('.cart-section');
    const isMobileOpen = cartSection.classList.toggle('open');
    
    // Crear/gestionar el overlay para cerrar al clickear fuera del carrito
    let overlay = document.getElementById('mobile-cart-overlay');
    if (isMobileOpen) {
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'mobile-cart-overlay';
            // Estilos para el overlay
            overlay.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 998;';
            overlay.onclick = toggleMobileCart; 
            document.body.appendChild(overlay);
        }
        overlay.style.display = 'block';
    } else {
        if (overlay) {
            overlay.style.display = 'none';
        }
    }
}


// -----------------------------------------------------------
// LÓGICA DE MODALES DE PAGO/CONFIRMACIÓN
// -----------------------------------------------------------

function closeCheckoutModal() {
    document.getElementById('checkoutModal').style.display = 'none';
}

function openCheckoutModal() {
    const modal = document.getElementById('checkoutModal');
    const orderType = localStorage.getItem('kiosco_order_type');
    const totalElement = document.getElementById('cart-total-value'); 
    const cartTotal = parseFloat(totalElement ? totalElement.dataset.total : 0);

    document.getElementById('modal-cart-total').textContent = formatCurrency(cartTotal);
    document.getElementById('modal-order-type').textContent = orderType;
    document.getElementById('selected_order_type_checkout').value = orderType;

    const mesaInputContainer = document.getElementById('mesa-input-container');
    const confirmButton = document.getElementById('confirm-payment-btn');

    if (orderType === 'MESA') {
        mesaInputContainer.style.display = 'block';
        document.getElementById('numero_mesa').value = ''; 
    } else {
        mesaInputContainer.style.display = 'none';
    }

    confirmButton.disabled = true;

    document.querySelectorAll('.payment-option').forEach(opt => opt.classList.remove('selected'));
    document.getElementById('selected_payment_method').value = '';

    modal.style.display = 'flex';

    // Si el carrito está abierto en móvil, cerrarlo al abrir el modal de checkout
    if (window.innerWidth <= 768) {
        const cartSection = document.querySelector('.cart-section');
        if (cartSection.classList.contains('open')) {
            toggleMobileCart();
        }
    }
}

function selectPaymentMethod(element) {
    document.querySelectorAll('.payment-option').forEach(opt => opt.classList.remove('selected'));
    element.classList.add('selected');
    const method = element.getAttribute('data-method');
    document.getElementById('selected_payment_method').value = method;
    
    document.getElementById('confirm-payment-btn').disabled = false;
}

function validateAndPlaceOrder() {
    const orderType = document.getElementById('selected_order_type_checkout').value;
    const paymentMethod = document.getElementById('selected_payment_method').value;
    const mesaInput = document.getElementById('numero_mesa');
    let mesaNumero = null;
    let valid = true;

    if (!paymentMethod) {
        showStatusMessage('Por favor, selecciona una forma de pago.', 'error');
        return;
    }

    if (orderType === 'MESA') {
        mesaNumero = mesaInput.value.trim();
        if (!mesaNumero || isNaN(parseInt(mesaNumero)) || parseInt(mesaNumero) <= 0) {
            showStatusMessage('Por favor, introduce un número de mesa válido.', 'error');
            valid = false;
        }
    }

    if (valid) {
        closeCheckoutModal();
        // Lógica de simulación de envío de pedido
        showFinalInstructionModal(orderType, paymentMethod, mesaNumero);
    }
}

function showFinalInstructionModal(orderType, paymentMethod, mesaNumero = null) {
    const modal = document.getElementById('finalInstructionModal');
    const titleElement = document.getElementById('final-instruction-title');
    const textElement = document.getElementById('final-instruction-text');
    const mesaInfoElement = document.getElementById('mesa-info-display');

    let instructionText = '';
    let mesaInfoText = '';

    if (orderType === 'EN BARRA' || orderType === 'LLEVAR') {
        instructionText = 'Tu pedido está en proceso. Por favor, **abona en la barra** y espera tu pedido.';
        titleElement.textContent = 'Pedido Confirmado';
    } else if (orderType === 'MESA') {
        instructionText = `¡Listo! Tu pedido para la **Mesa ${mesaNumero}** ha sido enviado.`;
        mesaInfoText = `El mozo recibirá tu pedido en la Mesa **${mesaNumero}**. El método de pago seleccionado es **${paymentMethod}**.`;
        titleElement.textContent = 'Pedido para MESA';
    } else {
         instructionText = `Tu pedido (${orderType}) ha sido registrado con el método **${paymentMethod}**.`;
         titleElement.textContent = 'Pedido Confirmado';
    }

    textElement.innerHTML = instructionText;
    mesaInfoElement.textContent = mesaInfoText;
    modal.style.display = 'flex';
}

function finishOrderProcess() {
    document.getElementById('finalInstructionModal').style.display = 'none';
    
    localStorage.removeItem('kiosco_cart');
    localStorage.removeItem('kiosco_order_type');
    cart = {}; 

    initializeAppDisplay();
}

// -----------------------------------------------------------
// Lógica del Modal de Modificaciones
// -----------------------------------------------------------

function generateUniqueCartId(productId, modifications) {
    let modString = modifications.sort((a, b) => a.id - b.id).map(m => `${m.id}:${m.quantity}`).join('|');
    return `${productId}_${modString}`;
}

function calculateModPrice() {
    let modTotal = 0;
    let selectedMods = [];
    
    const productMods = MODIFICATIONS_DATA[currentModProduct.id];

    for (const groupId in productMods) {
        const groupData = productMods[groupId];
        
        groupData.items.forEach(modItem => {
            const uniqueModId = `${modItem.id}`;
            const quantity = selectedModificationsQuantities[uniqueModId] || 0;
            
            if (quantity > 0) {
                modTotal += modItem.precio_adicional * quantity;
                selectedMods.push({
                    id: modItem.id,
                    name: modItem.name,
                    precio_adicional: modItem.precio_adicional,
                    quantity: quantity,
                    group_id: modItem.group_id
                });
            }
        });
    }
    
    currentModProduct.selectedModsList = selectedMods;
    
    const finalPricePerUnit = currentModProduct.basePrice + modTotal;
    currentModProduct.finalPricePerUnit = finalPricePerUnit;
    
    const totalToAdd = finalPricePerUnit * currentModProduct.quantity;

    document.getElementById('mod-total-price').textContent = formatCurrency(totalToAdd);
    return finalPricePerUnit;
}

function getGroupMaxQuantity(productId, groupId) {
    const productMods = MODIFICATIONS_DATA[productId];
    if (productMods && productMods[groupId]) {
        return productMods[groupId].mod_max_quantity;
    }
    return 999;
}

function enforceGroupQuantityConstraint(productId, targetModId, targetQuantity) {
    const productMods = MODIFICATIONS_DATA[productId];
    if (!productMods) return true;

    let targetGroupId = null;
    let totalInGroup = 0;
    let maxQuantity = 999;

    for (const groupId in productMods) {
        const group = productMods[groupId];
        const modItem = group.items.find(item => `${item.id}` === targetModId);
        if (modItem) {
            targetGroupId = groupId;
            maxQuantity = group.mod_max_quantity;
            break;
        }
    }

    if (!targetGroupId) return true;

    productMods[targetGroupId].items.forEach(modItem => {
        const modId = `${modItem.id}`;
        const currentQty = selectedModificationsQuantities[modId] || 0;
        if (modId === targetModId) {
            totalInGroup += targetQuantity;
        } else {
            totalInGroup += currentQty;
        }
    });
    
    return totalInGroup <= maxQuantity;
}

function updateModItemQuantity(modElement, change) {
    const modId = modElement.closest('.modification-option').getAttribute('data-mod-id');
    const inputElement = modElement.closest('.mod-item-quantity-controls').querySelector('.mod-item-quantity-input');
    let currentQuantity = parseInt(inputElement.value) || 0;
    let newQuantity = currentQuantity + change;
    
    if (newQuantity < 0) newQuantity = 0;
    
    if (newQuantity > currentQuantity) {
        if (!enforceGroupQuantityConstraint(currentModProduct.id, modId, newQuantity)) {
            showStatusMessage('No puedes añadir más items. El límite máximo para este grupo de modificaciones es ' + getGroupMaxQuantity(currentModProduct.id, modId.split('_')[0]), 'warning');
            return;
        }
    }
    
    inputElement.value = newQuantity;
    selectedModificationsQuantities[`${modId}`] = newQuantity;
    
    calculateModPrice();
}

function updateModalQuantity(action) {
    if (action === 'plus') {
        currentModProduct.quantity++;
    } else if (action === 'minus' && currentModProduct.quantity > 1) {
        currentModProduct.quantity--;
    }
    document.getElementById('add-mod-count').textContent = currentModProduct.quantity;
    calculateModPrice();
}

function openModificationsModal(productData) {
    currentModProduct = {
        id: productData.id,
        basePrice: parseFloat(productData.precio),
        quantity: 1,
        modGroups: MODIFICATIONS_DATA[productData.id] || {},
        finalPricePerUnit: parseFloat(productData.precio),
        selectedModsList: []
    };
    selectedModificationsQuantities = {};

    document.getElementById('mod-product-name').textContent = productData.nombre;
    document.getElementById('mod-base-price').textContent = formatCurrency(currentModProduct.basePrice);
    document.getElementById('add-mod-count').textContent = currentModProduct.quantity;
    
    const optionsContainer = document.getElementById('modifications-options-container');
    optionsContainer.innerHTML = '';
    
    const productMods = currentModProduct.modGroups;

    for (const groupId in productMods) {
        const groupData = productMods[groupId];
        const maxQty = groupData.mod_max_quantity;
        
        const groupHeader = document.createElement('h4');
        groupHeader.textContent = `Grupo ${groupId} (Máx: ${maxQty})`;
        optionsContainer.appendChild(groupHeader);
        
        groupData.items.forEach(modItem => {
            const isRequired = modItem.tipo === 'obligatorio';
            const modDiv = document.createElement('div');
            modDiv.className = `modification-option ${isRequired ? 'mod-required' : ''}`;
            modDiv.setAttribute('data-mod-id', modItem.id);
            
            let priceLabel = modItem.precio_adicional > 0 ? ` (+${formatCurrency(modItem.precio_adicional)})` : '';
            let requiredLabel = isRequired ? ` (OBLIGATORIO)` : '';
            
            // Determinar la cantidad inicial (1 si es obligatorio, 0 si es opcional)
            const initialQuantity = isRequired ? 1 : 0;
            
            modDiv.innerHTML = `
                <span>
                    ${modItem.name}
                    <span class="mod-price-label">${priceLabel}${requiredLabel}</span>
                </span>
                <div class="mod-item-quantity-controls">
                    <button class="btn-mod-qty" onclick="updateModItemQuantity(this, -1)">-</button>
                    <input type="number" min="0" value="${initialQuantity}" readonly class="mod-item-quantity-input">
                    <button class="btn-mod-qty" onclick="updateModItemQuantity(this, 1)">+</button>
                </div>
            `;
            optionsContainer.appendChild(modDiv);
            
            selectedModificationsQuantities[`${modItem.id}`] = initialQuantity;
        });
    }
    
    calculateModPrice();
    document.getElementById('modificationsModal').style.display = 'flex';
}

function closeModificationsModal() {
    document.getElementById('modificationsModal').style.display = 'none';
}

function confirmModifications() {
    const productId = currentModProduct.id;
    const quantity = currentModProduct.quantity;
    const finalPricePerUnit = calculateModPrice();
    const modifications = currentModProduct.selectedModsList;
    
    // 1. Validar requeridos
    for (const groupId in currentModProduct.modGroups) {
        const groupData = currentModProduct.modGroups[groupId];
        for (const modItem of groupData.items) {
            if (modItem.tipo === 'obligatorio') {
                const selectedQty = selectedModificationsQuantities[`${modItem.id}`] || 0;
                if (selectedQty < 1) {
                     showStatusMessage(`Debes seleccionar al menos un ítem del tipo obligatorio: ${modItem.name}`, 'error');
                     return;
                }
            }
        }
    }
    
    // 2. Crear ID único y añadir al carrito
    const uniqueId = generateUniqueCartId(productId, modifications);
    const totalPrice = finalPricePerUnit * quantity;

    if (cart[uniqueId]) {
        // Recalcular la cantidad total y el precio total
        const newTotalQuantity = cart[uniqueId].quantity + quantity;
        cart[uniqueId].price = finalPricePerUnit * newTotalQuantity;
        cart[uniqueId].quantity = newTotalQuantity;
        
    } else {
        cart[uniqueId] = {
            id: productId,
            name: PRODUCT_DATA[productId].nombre,
            price: totalPrice,
            quantity: quantity,
            modifications: modifications
        };
    }
    
    saveCart();
    closeModificationsModal();
}

// -----------------------------------------------------------
// Lógica de Carrito y Funciones de Utilidad
// -----------------------------------------------------------

function saveCart() {
    localStorage.setItem('kiosco_cart', JSON.stringify(cart));
    renderCart();
} 

function clearCart() {
    if (confirm('¿Estás seguro de que quieres vaciar el carrito?')) {
        localStorage.removeItem('kiosco_cart');
        cart = {};
        renderCart();
        // Asegurar que el carrito móvil se cierre y el botón flotante se oculte
        if (window.innerWidth <= 768) {
            const cartSection = document.querySelector('.cart-section');
            if (cartSection.classList.contains('open')) {
                toggleMobileCart();
            }
        }
    }
}

function addToCart(productElement) {
    const id = productElement.getAttribute('data-id');
    const hasMods = productElement.getAttribute('data-has-mods') === 'true';
    const productData = PRODUCT_DATA[id];
    if (hasMods) {
        openModificationsModal(productData);
    } else {
        const uniqueId = id + '_no_mods';
        const price = parseFloat(productData.precio);
        
        if (cart[uniqueId]) {
            const pricePerUnit = cart[uniqueId].price / cart[uniqueId].quantity;
            cart[uniqueId].quantity += 1;
            cart[uniqueId].price += pricePerUnit;
        } else {
            cart[uniqueId] = { 
                id: productData.id, 
                name: productData.nombre, 
                price: price,
                quantity: 1, 
                modifications: null 
            };
        }
        saveCart();
    }
}

function updateQuantity(uniqueId, change) {
    if (!cart[uniqueId]) return;

    const pricePerUnit = cart[uniqueId].price / cart[uniqueId].quantity;
    
    cart[uniqueId].quantity += change;
    cart[uniqueId].price += (pricePerUnit * change);

    if (cart[uniqueId].quantity <= 0) {
        delete cart[uniqueId];
    }

    saveCart();
}

function renderCart() {
    const cartItemsContainer = document.getElementById('cart-items');
    const checkoutBtn = document.getElementById('checkout-btn');
    const cartSubtotalValue = document.getElementById('cart-subtotal-value');
    const cartTotalValue = document.getElementById('cart-total-value');
    const floatingBtn = document.getElementById('floating-cart-btn'); 

    // Elementos del botón flotante para móvil
    const mobileCartCount = document.getElementById('mobile-cart-count');
    const mobileCartTotal = document.getElementById('mobile-cart-total');
    
    cartItemsContainer.innerHTML = '';
    let total = 0;
    let itemCount = 0;

    if (Object.keys(cart).length === 0) {
        cartItemsContainer.innerHTML = '<p style="text-align: center; color: var(--color-secondary);">El carrito está vacío.</p>';
        checkoutBtn.disabled = true;
        cartTotalValue.dataset.total = 0;
        
        // Ocultar botón flotante
        if (floatingBtn) floatingBtn.style.display = 'none';

    } else {
        Object.keys(cart).forEach(uniqueId => {
            const item = cart[uniqueId];
            total += item.price;
            itemCount += item.quantity;
            let modHtml = ''; 
            if (item.modifications && item.modifications.length > 0) {
                modHtml = `<div class="cart-item-mods">`;
                item.modifications.forEach(m => {
                    const totalModPrice = m.precio_adicional * m.quantity;
                    const qtyLabel = m.quantity > 1 ? ` (x${m.quantity})` : '';
                    const priceLabel = totalModPrice > 0 ? ` +${formatCurrency(totalModPrice)}` : '';
                    modHtml += `<span>${m.name}${qtyLabel}${priceLabel}</span>, `;
                });
                modHtml = modHtml.slice(0, -2);
                modHtml += `</div>`;
            }

            const pricePerUnit = item.price / item.quantity;
            const itemDiv = document.createElement('div');
            itemDiv.className = 'cart-item';
            itemDiv.innerHTML = `
                <div class="cart-item-info">
                    <div class="cart-item-name">${item.name}</div>
                    ${modHtml}
                    <small>${formatCurrency(pricePerUnit)} x ${item.quantity} uni.</small>
                </div>
                <div class="cart-quantity-controls">
                    <button class="btn-secondary" onclick="updateQuantity('${uniqueId}', -1)">-</button>
                    <span>${item.quantity}</span>
                    <button class="btn-secondary" onclick="updateQuantity('${uniqueId}', 1)">+</button>
                </div>
                <div class="cart-item-total">${formatCurrency(item.price)}</div>
            `;
            cartItemsContainer.appendChild(itemDiv);
        });
        checkoutBtn.disabled = false;
        
        // Mostrar botón flotante si es necesario
        if (floatingBtn && window.innerWidth <= 768) {
             floatingBtn.style.display = 'flex';
        }
    }

    cartSubtotalValue.textContent = formatCurrency(total);
    cartTotalValue.textContent = formatCurrency(total);
    cartTotalValue.dataset.total = total.toFixed(2);
    
    // Sincronizar botón flotante
    if (mobileCartCount && mobileCartTotal) {
        mobileCartCount.textContent = itemCount;
        mobileCartTotal.textContent = formatCurrency(total);
    }
}

// Lógica para mostrar la pantalla principal o la de inicio
function initializeAppDisplay() {
    // 1. Cargar carrito
    try {
        const storedCart = localStorage.getItem('kiosco_cart');
        if (storedCart) {
            cart = JSON.parse(storedCart);
        } else {
            cart = {};
        }
    } catch (e) {
        console.error("Error cargando el carrito desde localStorage:", e);
        cart = {};
    }
    
    // 2. Aplicar el tema
    currentTheme = localStorage.getItem('kiosco_theme') || 'light';
    setTheme(currentTheme); 

    // 3. Mostrar/Ocultar interfaz
    selectedOrderType = localStorage.getItem('kiosco_order_type');
    document.getElementById('selected-order-type').textContent = selectedOrderType || 'N/A';

    if (selectedOrderType) {
        document.getElementById('kiosco-start-screen').style.display = 'none';
        document.getElementById('main-app-container').style.display = 'flex';
        renderCart();
        
        // Ocultar el carrito en móvil al iniciar la app
        if (window.innerWidth <= 768) {
            document.querySelector('.cart-section').classList.remove('open');
        }
    } else {
        document.getElementById('kiosco-start-screen').style.display = 'flex';
        document.getElementById('main-app-container').style.display = 'none';
    }
}

// Inicialización al cargar la página
document.addEventListener('DOMContentLoaded', () => {
    // Lógica de selección del tipo de pedido
    document.querySelectorAll('.start-order-btn').forEach(button => {
        button.addEventListener('click', (event) => {
            selectedOrderType = event.currentTarget.getAttribute('data-order-type');
            localStorage.setItem('kiosco_order_type', selectedOrderType);
            initializeAppDisplay();
        });
    });
    
    // NUEVO: Listener para el toggle de tema
    document.getElementById('theme-toggle').addEventListener('click', toggleTheme);
    
    initializeAppDisplay();
});

// Cerrar modales al hacer click fuera de ellos
window.onclick = function(event) {
    const modalCheckout = document.getElementById('checkoutModal');
    const modalMods = document.getElementById('modificationsModal');
    const modalFinal = document.getElementById('finalInstructionModal');
    const overlay = document.getElementById('mobile-cart-overlay');
    
    if (event.target === modalCheckout) {
        closeCheckoutModal();
    }
    if (event.target === modalMods) {
        closeModificationsModal();
    }
    if (event.target === overlay) {
        toggleMobileCart();
    }
}