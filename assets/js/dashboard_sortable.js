/**
 * EduCore Dashboard Customizer (Sortable UI)
 * Dashboard layout engine:
 * - each dashboard row is a shared SortableJS widget list.
 * - widgets/cards can move in both axes inside and between rows.
 */

document.addEventListener('DOMContentLoaded', function () {
    if (typeof Sortable === 'undefined') {
        console.warn('SortableJS is not loaded.');
        return;
    }

    const pageName = window.location.pathname.split('/').pop().split('.')[0] || 'dashboard';
    const storageKey = `eduCoreSortOrder_${pageName}`;
    const widgetClass = 'sortable-widget-item';
    const generatedRowClass = 'dashboard-generated-row';
    const generatedRowPrefix = `${pageName}-generated-row-`;
    const initializedContainers = [];
    const sortableInstances = new WeakMap();
    let lastDragEndedAt = 0;
    let lastMoveIntent = null;
    let lastPointerPosition = null;
    let pendingLivePreview = null;
    let livePreviewFrame = null;
    let activeDragItem = null;
    promoteStandaloneStatRows();
    const canvases = Array.from(document.querySelectorAll('.dashboard-canvas.sortable-dashboard'));

    if (!canvases.length) return;

    function promoteStandaloneStatRows() {
        Array.from(document.querySelectorAll('.row')).forEach(function (row) {
            if (row.closest('.dashboard-canvas') || row.closest('.modal')) return;

            const statColumns = Array.from(row.children).filter(function (child) {
                return child.matches('.col, [class*="col-"]') && !!child.querySelector(':scope > .stat-card');
            });
            if (!statColumns.length) return;

            const cardKeys = statColumns.map(function (column, index) {
                const label = (column.querySelector('.stat-card-label') || {}).textContent || '';
                const key = `${pageName}-stat-${hashString(`${label.trim()}-${index}`)}`;
                if (!column.dataset.cardId) column.dataset.cardId = key;
                return key;
            });

            if (!row.id) row.id = `${pageName}-stats-${hashString(cardKeys.join('|'))}`;
            row.classList.add('sortable-dashboard');

            const canvas = document.createElement('div');
            canvas.className = 'dashboard-canvas sortable-dashboard';
            canvas.dataset.autoStats = 'true';
            row.parentNode.insertBefore(canvas, row);
            canvas.appendChild(row);
        });
    }

    let savedOrder = loadSortOrder(storageKey);
    injectSortableStyles();
    normalizeDashboardRows(canvases);
    savedOrder = pruneGeneratedRowOrder(savedOrder, storageKey);

    canvases.forEach(function (canvas) {
        Array.from(canvas.querySelectorAll(':scope .sortable-dashboard:not(.dashboard-canvas)')).forEach(prepareSortableContainer);
    });
    const defaultOrder = getCurrentOrderMap();
    restoreSortOrder(savedOrder);
    cleanupLayoutRows(canvases);
    initializedContainers.forEach(initSortableContainer);
    bindResetButton(storageKey, defaultOrder);

    function injectSortableStyles() {
        if (document.getElementById('sortable-styles')) return;

        const style = document.createElement('style');
        style.id = 'sortable-styles';
        style.textContent = `
            .sortable-ghost {
                opacity: 1;
                background: rgba(248, 250, 252, 0.72) !important;
                border: 1px dashed rgba(148, 163, 184, 0.75) !important;
                border-radius: 12px;
                box-shadow: none !important;
            }
            .sortable-ghost > * {
                opacity: 0;
                visibility: hidden;
            }
            .sortable-drag,
            .sortable-fallback {
                opacity: 0.98 !important;
                animation: none !important;
                box-shadow: 0 18px 34px rgba(15, 23, 42, 0.18) !important;
                cursor: grabbing !important;
                z-index: 10050 !important;
                pointer-events: none !important;
                display: block !important;
                visibility: visible !important;
                will-change: transform;
                transition: none !important;
            }
            .sortable-chosen {
                cursor: grabbing !important;
            }
            body.dashboard-sortable-dragging,
            body.dashboard-sortable-dragging * {
                cursor: grabbing !important;
            }
            body.dashboard-sortable-dragging .dashboard-canvas .stat-card:hover,
            body.dashboard-sortable-dragging .dashboard-canvas .card:hover,
            body.dashboard-sortable-dragging .dashboard-canvas .premium-card:hover {
                transform: none !important;
            }
            body.dashboard-sortable-dragging .dashboard-canvas .stat-card:hover .stat-card-icon {
                transform: none !important;
            }
            .sortable-managed-item {
                min-width: 0;
                will-change: transform;
            }
            .dashboard-canvas > .dashboard-generated-row {
                --bs-gutter-y: 0;
            }
            .dashboard-canvas > .dashboard-generated-row > .dashboard-section {
                flex: 0 0 100%;
                width: 100%;
                max-width: 100%;
                margin-bottom: 1.5rem !important;
            }
            .dashboard-canvas > .dashboard-generated-row > .dashboard-section:last-child {
                margin-bottom: 0 !important;
            }
            .dashboard-canvas > .dashboard-generated-row > .sortable-stat-widget {
                flex: 0 0 auto;
                width: 100%;
                max-width: 100%;
            }
            @media (min-width: 576px) {
                .dashboard-canvas > .dashboard-generated-row > .sortable-stat-widget {
                    width: 50%;
                    max-width: 50%;
                }
            }
            @media (min-width: 768px) {
                .dashboard-canvas > .dashboard-generated-row > .sortable-stat-widget {
                    width: 33.333333%;
                    max-width: 33.333333%;
                }
            }
            @media (min-width: 992px) {
                .dashboard-canvas > .dashboard-generated-row > .sortable-stat-widget {
                    width: 25%;
                    max-width: 25%;
                }
            }
            @media (min-width: 1400px) {
                .dashboard-canvas > .dashboard-generated-row > .sortable-stat-widget {
                    width: 25%;
                    max-width: 25%;
                }
            }
            body.dashboard-sortable-dragging .dashboard-canvas .sortable-managed-item,
            body.dashboard-sortable-dragging .dashboard-canvas .sortable-managed-item * {
                -webkit-user-select: none;
                user-select: none;
            }
            .dashboard-canvas .sortable-managed-item a,
            .dashboard-canvas .sortable-managed-item img {
                -webkit-user-drag: none;
            }
            .sortable-whole-item {
                cursor: grab;
            }
            .sortable-whole-item:active {
                cursor: grabbing;
            }
            .sortable-whole-item input,
            .sortable-whole-item textarea,
            .sortable-whole-item select,
            .sortable-whole-item button,
            .sortable-whole-item .btn,
            .sortable-whole-item [role="button"],
            .sortable-whole-item .dropdown-menu,
            .sortable-whole-item .dropdown-item,
            .sortable-whole-item .dataTables_filter,
            .sortable-whole-item .dataTables_length,
            .sortable-whole-item .dataTables_paginate,
            .sortable-whole-item .no-sort {
                cursor: auto;
                -webkit-user-select: auto;
                user-select: auto;
            }
            .dashboard-canvas > .sortable-managed-item {
                position: relative;
            }
            .dashboard-canvas .drag-handle,
            .dashboard-canvas .sortable-auto-handle,
            .dashboard-canvas .sortable-section-handle {
                display: none !important;
                width: 0 !important;
                height: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: hidden !important;
            }
            .sortable-managed-item > .stat-card,
            .sortable-managed-item > a > .stat-card {
                position: relative;
            }
            .sortable-drag .stat-card,
            .sortable-fallback .stat-card,
            .sortable-drag .card,
            .sortable-fallback .card {
                transition: none !important;
                transform: none !important;
            }
        `;
        document.head.appendChild(style);
    }

    function prepareSortableContainer(container, index) {
        if (sortableInstances.has(container)) return;

        const directItems = Array.from(container.children).filter(isSortableElement);

        const isGeneratedRow = container.classList.contains(generatedRowClass);

        if (directItems.length < 1 && !isGeneratedRow) return;

        const containerId = getContainerId(container, index, directItems);
        const commonItemClass = widgetClass;

        container.dataset.sortableId = containerId;
        container.dataset.sortableItemClass = commonItemClass;

        directItems.forEach(function (item, itemIndex) {
            prepareSortableItem(container, item, itemIndex, commonItemClass);
        });

        initializedContainers.push({
            element: container,
            itemSelector: `.${commonItemClass}`,
            handleSelector: null,
            filterSelector: getFilterSelector()
        });
    }

    function prepareSortableItem(container, item, itemIndex, commonItemClass) {
        item.classList.add('sortable-managed-item', commonItemClass);
        item.dataset.sortableItemId = getItemId(item, container.dataset.sortableId, itemIndex);
        item.classList.add('sortable-whole-item');
        item.classList.toggle('sortable-stat-widget', isCompactStatWidget(item));

        item.querySelectorAll('a, img').forEach(function (el) {
            el.draggable = false;
        });
    }

    function initSortableContainer(config) {
        const instance = new Sortable(config.element, {
            animation: 220,
            easing: 'cubic-bezier(0.2, 0, 0, 1)',
            swapThreshold: 0.65,
            invertSwap: false,
            forceFallback: true,
            fallbackOnBody: true,
            fallbackTolerance: 5,
            touchStartThreshold: 4,
            emptyInsertThreshold: 76,
            group: {
                name: `eduCoreDashboardWidgets_${pageName}`,
                pull: true,
                put: true
            },
            draggable: config.itemSelector,
            handle: config.handleSelector,
            direction: getDirectionResolver(),
            filter: config.filterSelector,
            preventOnFilter: false,
            ghostClass: 'sortable-ghost',
            dragClass: 'sortable-drag',
            fallbackClass: 'sortable-fallback',
            chosenClass: 'sortable-chosen',
            onMove: handleSortableMove,
            onClone: setDragCloneDimensions,
            onStart: function (evt) {
                lastMoveIntent = null;
                lastPointerPosition = null;
                activeDragItem = evt.item;
                document.body.classList.add('dashboard-sortable-dragging');
                bindLivePointerTracking();
            },
            onEnd: function (evt) {
                flushLiveMovePreview();
                unbindLivePointerTracking();
                activeDragItem = null;
                document.body.classList.remove('dashboard-sortable-dragging');
                applyVisualDropPosition(evt);
                prepareMovedItems();
                cleanupLayoutRows(canvases);
                saveSortOrder(storageKey);
                lastMoveIntent = null;
                lastPointerPosition = null;
                pendingLivePreview = null;
                lastDragEndedAt = Date.now();
            }
        });

        sortableInstances.set(config.element, instance);
    }

    function setDragCloneDimensions(evt) {
        const source = evt && evt.item;
        const clone = evt && evt.clone;
        if (!source || !clone) return;

        const rect = source.getBoundingClientRect();
        clone.classList.remove('animate-up');
        Array.from(clone.classList).forEach(function (className) {
            if (/^delay-\d+$/.test(className)) {
                clone.classList.remove(className);
            }
        });
        clone.style.animation = 'none';
        clone.style.opacity = '0.98';
        clone.style.display = 'block';
        clone.style.visibility = 'visible';
        clone.style.width = `${rect.width}px`;
        clone.style.height = `${rect.height}px`;
        clone.style.boxSizing = 'border-box';
        clone.style.margin = '0';
        clone.style.pointerEvents = 'none';
    }

    function bindLivePointerTracking() {
        document.addEventListener('mousemove', handleLivePointerMove, true);
        document.addEventListener('touchmove', handleLivePointerMove, true);
    }

    function unbindLivePointerTracking() {
        document.removeEventListener('mousemove', handleLivePointerMove, true);
        document.removeEventListener('touchmove', handleLivePointerMove, true);
    }

    function handleLivePointerMove(event) {
        if (!activeDragItem) return;

        const pointer = getPointerPosition(event);
        if (!pointer) return;

        lastPointerPosition = pointer;
        const container = getContainerAtPointer(pointer) || activeDragItem.parentElement;
        if (!container || !container.dataset || !container.dataset.sortableItemClass) return;

        queueLiveMovePreview({
            dragged: activeDragItem,
            container: container,
            pointer: pointer,
            fallbackIntent: lastMoveIntent
        });
    }

    function getContainerAtPointer(pointer) {
        const elementAtPoint = document.elementFromPoint(pointer.x, pointer.y);
        if (!elementAtPoint) return null;

        const container = elementAtPoint.closest('.sortable-dashboard:not(.dashboard-canvas)');
        if (!container || !container.dataset || !container.dataset.sortableItemClass) return null;

        return container;
    }

    function isVerticalSectionContainer(container) {
        if (!container || !container.classList.contains(generatedRowClass)) return false;

        return Array.from(container.children).some(function (child) {
            return child.classList && child.classList.contains('dashboard-section');
        });
    }

    function prepareMovedItems() {
        initializedContainers.forEach(function (config) {
            const container = config.element;
            const directItems = Array.from(container.children).filter(isSortableElement);

            directItems.forEach(function (item, index) {
                if (!item.classList.contains(container.dataset.sortableItemClass)) {
                    item.classList.add('sortable-managed-item', container.dataset.sortableItemClass);
                }
                if (!item.dataset.sortableItemId) {
                    item.dataset.sortableItemId = getItemId(item, container.dataset.sortableId, index);
                }
                item.classList.add('sortable-whole-item');
                item.classList.toggle('sortable-stat-widget', isCompactStatWidget(item));
            });
        });
    }

    function isCompactStatWidget(item) {
        return !!item.querySelector(':scope > .stat-card, :scope > a > .stat-card');
    }

    function normalizeDashboardRows(canvasList) {
        canvasList.forEach(function (canvas) {
            Array.from(canvas.children).forEach(function (child) {
                if (!isSortableElement(child)) return;

                if (child.classList.contains('row')) {
                    child.classList.add('sortable-dashboard');
                    return;
                }

                if (child.classList.contains('sortable-dashboard')) return;

                const row = document.createElement('div');
                row.className = `row g-4 mb-4 sortable-dashboard ${generatedRowClass}`;
                row.id = `${generatedRowPrefix}${hashString(getItemId(child, 'canvas', 0))}`;

                canvas.insertBefore(row, child);
                row.appendChild(child);
            });
        });
    }

    document.addEventListener('click', function (event) {
        if (Date.now() - lastDragEndedAt > 250) return;
        if (event.target.closest('a')) {
            event.preventDefault();
            event.stopPropagation();
        }
    }, true);

    function isSortableElement(el) {
        return el
            && el.nodeType === 1
            && !el.classList.contains('no-sort')
            && !el.classList.contains('drag-handle')
            && !el.classList.contains('sortable-section-handle');
    }

    function getFilterSelector() {
        const interactiveSelector = 'input, textarea, select, option, button, .btn, [role="button"], .dropdown-menu, .dropdown-item, .dataTables_filter, .dataTables_length, .dataTables_paginate, .no-sort, .section-resize-handle';
        return interactiveSelector;
    }

    function getContainerId(container, index, directItems) {
        if (container.id) return container.id;
        if (container.classList.contains('dashboard-canvas')) return `${pageName}-dashboard-canvas`;

        const childIds = directItems.map(function (item) {
            return item.id || item.dataset.cardId || item.dataset.sectionId || '';
        }).filter(Boolean);

        if (childIds.length) return `auto-container-${hashString(childIds.join('|'))}`;

        const heading = getReadableText(container);
        return `auto-container-${hashString(heading || String(index))}`;
    }

    function getItemId(item, containerId, index) {
        if (item.id) return item.id;
        if (item.dataset.cardId) return item.dataset.cardId;
        if (item.dataset.sectionId) return item.dataset.sectionId;
        if (item.dataset.sortableItemId) return item.dataset.sortableItemId;

        const childIds = Array.from(item.children).map(function (child) {
            return child.id || child.dataset.cardId || child.dataset.sectionId || '';
        }).filter(Boolean);

        if (childIds.length) return `group-${hashString(childIds.join('|'))}`;

        return `auto-${containerId}-${hashString(getReadableText(item) || String(index))}`;
    }

    function getReadableText(el) {
        const textEl = el.querySelector('.stat-card-label, .card-header h5, .card-header h6, h5, h6, [data-sort-label]');
        const text = textEl ? textEl.textContent : el.textContent;
        return normalizeText(text).slice(0, 160);
    }

    function normalizeText(text) {
        return String(text || '').replace(/\s+/g, ' ').trim();
    }

    function hashString(value) {
        let hash = 0;
        const input = String(value || '');
        for (let i = 0; i < input.length; i++) {
            hash = ((hash << 5) - hash) + input.charCodeAt(i);
            hash |= 0;
        }
        return Math.abs(hash).toString(36);
    }

    function getDirectionResolver() {
        return function (evt, target, dragEl) {
            if (!target || !dragEl) return 'horizontal';

            const container = target.parentElement;
            if (isVerticalSectionContainer(container)) {
                return 'vertical';
            }

            if (container) {
                const containerStyles = window.getComputedStyle(container);
                if (container.classList.contains('row') || containerStyles.display === 'flex') {
                    return 'horizontal';
                }
            }

            const targetRect = target.getBoundingClientRect();
            const dragRect = dragEl.getBoundingClientRect();
            const targetCenterY = targetRect.top + (targetRect.height / 2);
            const dragCenterY = dragRect.top + (dragRect.height / 2);
            const verticalOffset = Math.abs(targetCenterY - dragCenterY);
            const rowThreshold = Math.min(targetRect.height || 0, dragRect.height || 0) * 0.35;

            return verticalOffset > rowThreshold ? 'vertical' : 'horizontal';
        };
    }

    function handleSortableMove(evt, originalEvent) {
        const related = evt.related;
        const dragged = evt.dragged;
        if (!related || !dragged || related === dragged) return true;

        const container = related.parentElement;
        if (isVerticalSectionContainer(container)) {
            const pointerY = originalEvent && typeof originalEvent.clientY === 'number'
                ? originalEvent.clientY
                : null;
            if (pointerY === null) return true;

            lastPointerPosition = getPointerPosition(originalEvent);
            updateDragMirror(lastPointerPosition);

            const relatedCenterY = getElementCenterY(related);
            const insertAfter = pointerY > relatedCenterY;
            lastMoveIntent = {
                dragged: dragged,
                related: related,
                container: container,
                insertAfter: insertAfter
            };
            queueLiveMovePreview({
                dragged: dragged,
                container: container,
                pointer: lastPointerPosition,
                fallbackIntent: lastMoveIntent
            });

            return insertAfter ? 1 : -1;
        }

        if (!container || !isHorizontalContainer(container) || !isRtlContext(container)) {
            return true;
        }

        const pointerX = originalEvent && typeof originalEvent.clientX === 'number'
            ? originalEvent.clientX
            : null;
        if (pointerX === null) return true;
        lastPointerPosition = getPointerPosition(originalEvent);
        updateDragMirror(lastPointerPosition);

        const relatedRect = related.getBoundingClientRect();
        const relatedCenterX = relatedRect.left + (relatedRect.width / 2);

        const insertAfter = pointerX < relatedCenterX;
        lastMoveIntent = {
            dragged: dragged,
            related: related,
            container: container,
            insertAfter: insertAfter
        };
        queueLiveMovePreview({
            dragged: dragged,
            container: container,
            pointer: lastPointerPosition,
            fallbackIntent: lastMoveIntent
        });

        return insertAfter ? 1 : -1;
    }

    function queueLiveMovePreview(intent) {
        pendingLivePreview = intent;
        if (livePreviewFrame) return;

        livePreviewFrame = window.requestAnimationFrame(function () {
            livePreviewFrame = null;
            flushLiveMovePreview();
        });
    }

    function flushLiveMovePreview() {
        if (!pendingLivePreview) return;

        const intent = pendingLivePreview;
        pendingLivePreview = null;
        applyLiveMovePreview(intent);
    }

    function applyLiveMovePreview(intent) {
        const dragged = intent && intent.dragged;
        const container = intent && intent.container;
        const pointer = intent && intent.pointer;

        if (!dragged || !container) return;
        if (!document.documentElement.contains(dragged)) return;
        if (!document.documentElement.contains(container)) return;

        animateLiveReorder(container, function () {
            if (pointer && isVerticalSectionContainer(container)) {
                placeItemVerticallyByPointer(dragged, container, pointer);
                return;
            }

            if (pointer) {
                placeItemByPointer(dragged, container, pointer);
                return;
            }

            applyLastMoveIntent(dragged, intent.fallbackIntent);
        });
    }

    function animateLiveReorder(container, mutate) {
        const items = Array.from(container.children).filter(function (child) {
            return child.classList.contains(container.dataset.sortableItemClass || widgetClass);
        });

        items.forEach(resetLiveShift);
        mutate();
        items.forEach(resetLiveShift);
    }

    function resetLiveShift(item) {
        item.classList.remove('sortable-live-shift');
        item.style.transition = '';
        item.style.transform = '';
    }

    function applyVisualDropPosition(evt) {
        const draggedItem = evt && evt.item;
        if (!draggedItem) return;

        const pointer = getPointerPosition(evt.originalEvent) || lastPointerPosition;
        if (!pointer) {
            applyLastMoveIntent(draggedItem);
            return;
        }

        const container = evt.to || draggedItem.parentElement;
        if (isVerticalSectionContainer(container)) {
            placeItemVerticallyByPointer(draggedItem, container, pointer);
            return;
        }

        if (!container || !isHorizontalContainer(container) || !isRtlContext(container)) {
            applyLastMoveIntent(draggedItem);
            return;
        }

        placeItemByPointer(draggedItem, container, pointer);
    }

    function placeItemVerticallyByPointer(draggedItem, container, pointer) {
        const itemClass = container.dataset.sortableItemClass || widgetClass;
        const siblings = Array.from(container.children).filter(function (child) {
            return child !== draggedItem && child.classList.contains(itemClass);
        });

        if (!siblings.length) {
            container.appendChild(draggedItem);
            return;
        }

        const insertBeforeNode = siblings.find(function (item) {
            return pointer.y < getElementCenterY(item);
        });

        if (insertBeforeNode) {
            container.insertBefore(draggedItem, insertBeforeNode);
            return;
        }

        container.appendChild(draggedItem);
    }

    function placeItemByPointer(draggedItem, container, pointer) {
        const itemClass = container.dataset.sortableItemClass || widgetClass;
        const siblings = Array.from(container.children).filter(function (child) {
            return child !== draggedItem && child.classList.contains(itemClass);
        });

        if (!siblings.length) {
            container.appendChild(draggedItem);
            return;
        }

        const lineItems = getNearestVisualLineItems(siblings, pointer.y);
        const orderedItems = lineItems.slice().sort(function (a, b) {
            return getElementCenterX(b) - getElementCenterX(a);
        });

        const insertBeforeNode = orderedItems.find(function (item) {
            return pointer.x > getElementCenterX(item);
        });

        if (insertBeforeNode) {
            container.insertBefore(draggedItem, insertBeforeNode);
            return;
        }

        orderedItems[orderedItems.length - 1].after(draggedItem);
    }

    function applyLastMoveIntent(draggedItem, explicitIntent) {
        const moveIntent = explicitIntent || lastMoveIntent;
        if (!moveIntent || !draggedItem) return;

        const related = moveIntent.related;
        const container = moveIntent.container;
        if (!related || !container || related === draggedItem) return;
        if (!document.documentElement.contains(related) || !document.documentElement.contains(container)) return;

        const currentParent = draggedItem.parentElement;
        if (currentParent !== container) {
            container.appendChild(draggedItem);
        }

        if (moveIntent.insertAfter) {
            related.after(draggedItem);
        } else {
            related.before(draggedItem);
        }
    }

    function getPointerPosition(event) {
        if (event && event.touches && event.touches.length) {
            return {
                x: event.touches[0].clientX,
                y: event.touches[0].clientY
            };
        }

        if (!event || typeof event.clientX !== 'number' || typeof event.clientY !== 'number') {
            return null;
        }

        return {
            x: event.clientX,
            y: event.clientY
        };
    }

    function getNearestVisualLineItems(items, pointerY) {
        if (!items.length) return [];

        let nearestCenterY = null;
        let nearestDistance = Infinity;

        items.forEach(function (item) {
            const centerY = getElementCenterY(item);
            const distance = Math.abs(centerY - pointerY);
            if (distance < nearestDistance) {
                nearestDistance = distance;
                nearestCenterY = centerY;
            }
        });

        return items.filter(function (item) {
            const rect = item.getBoundingClientRect();
            const tolerance = Math.max(32, rect.height * 0.7);
            return Math.abs(getElementCenterY(item) - nearestCenterY) <= tolerance;
        });
    }

    function getElementCenterX(element) {
        const rect = element.getBoundingClientRect();
        return rect.left + (rect.width / 2);
    }

    function getElementCenterY(element) {
        const rect = element.getBoundingClientRect();
        return rect.top + (rect.height / 2);
    }

    function isHorizontalContainer(container) {
        const containerStyles = window.getComputedStyle(container);
        return container.classList.contains('row') || containerStyles.display === 'flex';
    }

    function isRtlContext(element) {
        const explicitDirElement = element.closest('[dir]');
        if (explicitDirElement && explicitDirElement.getAttribute('dir') === 'rtl') {
            return true;
        }

        return window.getComputedStyle(element).direction === 'rtl'
            || window.getComputedStyle(document.documentElement).direction === 'rtl';
    }

    function loadSortOrder(key) {
        try {
            const parsed = JSON.parse(localStorage.getItem(key) || 'null');
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (e) {
            localStorage.removeItem(key);
            return {};
        }
    }

    function pruneGeneratedRowOrder(orderMap, key) {
        if (!orderMap || typeof orderMap !== 'object') return {};

        let changed = false;
        const pruned = {};
        Object.keys(orderMap).forEach(function (containerId) {
            if (containerId.startsWith(generatedRowPrefix) && !document.getElementById(containerId)) {
                changed = true;
                return;
            }
            pruned[containerId] = orderMap[containerId];
        });

        if (changed) {
            localStorage.setItem(key, JSON.stringify(pruned));
        }

        return pruned;
    }

    function bindResetButton(key, defaultOrderMap) {
        const resetBtn = document.getElementById('reset-dashboard-prefs');
        if (!resetBtn) return;

        resetBtn.addEventListener('click', function () {
            localStorage.removeItem(key);
            savedOrder = {};
            window.location.reload();
        });
    }

    function saveSortOrder(key) {
        const orderMap = getCurrentOrderMap();

        localStorage.setItem(key, JSON.stringify(orderMap));
        savedOrder = orderMap;
    }

    function getCurrentOrderMap() {
        const orderMap = {};
        initializedContainers.forEach(function (config) {
            const container = config.element;
            const itemIds = Array.from(container.children)
                .filter(function (child) {
                    return child.classList.contains(container.dataset.sortableItemClass);
                })
                .map(function (child) {
                    return child.dataset.sortableItemId || getItemId(child, container.dataset.sortableId, 0);
                })
                .filter(Boolean);

            if (!itemIds.length && container.classList.contains(generatedRowClass)) return;

            orderMap[container.dataset.sortableId] = itemIds;
        });

        return orderMap;
    }

    function restoreSortOrder(orderMap) {
        if (!orderMap || typeof orderMap !== 'object') return;

        const globalItems = new Map();
        initializedContainers.forEach(function (config) {
            Array.from(config.element.children).forEach(function (child) {
                if (!child.classList.contains(config.element.dataset.sortableItemClass)) return;
                const itemId = child.dataset.sortableItemId;
                if (itemId && !globalItems.has(itemId)) {
                    globalItems.set(itemId, child);
                }
            });
        });

        const claimed = new Set();

        initializedContainers.forEach(function (config) {
            const container = config.element;
            const itemIds = orderMap[container.dataset.sortableId];
            if (!Array.isArray(itemIds)) return;

            const currentChildren = Array.from(container.children).filter(function (child) {
                return child.classList.contains(container.dataset.sortableItemClass);
            });
            const frag = document.createDocumentFragment();

            itemIds.forEach(function (id) {
                const child = globalItems.get(id);
                if (child && !claimed.has(child)) {
                    frag.appendChild(child);
                    claimed.add(child);
                }
            });

            currentChildren.forEach(function (child) {
                if (!claimed.has(child)) {
                    frag.appendChild(child);
                    claimed.add(child);
                }
            });

            container.appendChild(frag);
        });
    }

    function cleanupLayoutRows(canvasList) {
        cleanupGeneratedRows(canvasList);
    }

    function cleanupGeneratedRows(canvasList) {
        canvasList.forEach(function (canvas) {
            Array.from(canvas.querySelectorAll(`:scope > .${generatedRowClass}`)).forEach(function (row) {
                if (row.querySelector(`:scope > .${widgetClass}`)) return;
                removeSortableRow(row);
            });
        });
    }

    function removeSortableRow(row) {
        if (sortableInstances.has(row)) {
            sortableInstances.get(row).destroy();
            sortableInstances.delete(row);
        }

        const configIndex = initializedContainers.findIndex(function (config) {
            return config.element === row;
        });
        if (configIndex !== -1) {
            initializedContainers.splice(configIndex, 1);
        }

        row.remove();
    }

});
