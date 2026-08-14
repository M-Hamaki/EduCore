/**
 * EduVisual v3.0 - محرك الرسوم التفاعلية المتقدم
 * Advanced SVG Visualization Engine with Smart Layout
 * 
 * Features:
 * - Canvas-based text measurement for accurate Arabic text sizing
 * - Adaptive radial layout with collision avoidance
 * - Auto-fit viewBox for all visualizations
 * - Dynamic node sizing based on content
 * - Smooth bezier connections with edge-aware routing
 * - Touch & mouse zoom/pan with pinch support
 * - PNG/SVG export with theme switching
 * 
 * @version 3.0
 * @date 2026-02-25
 */

const EduVisual = (() => {
    'use strict';

    // ==========================================
    // 1. سمات الألوان (Color Themes)
    // ==========================================
    const THEMES = {
        modern: {
            name: 'عصري',
            bg: '#f8fafc',
            centerText: '#ffffff',
            nodeBg: '#ffffff',
            nodeText: '#1e293b',
            nodeStroke: '#e2e8f0',
            connectionColor: '#94a3b8',
            branchColors: ['#10b981', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4', '#f97316'],
            shadow: 'rgba(0,0,0,0.08)',
        },
        ocean: {
            name: 'محيطي',
            bg: '#f0f9ff',
            centerText: '#ffffff',
            nodeBg: '#ffffff',
            nodeText: '#0c4a6e',
            nodeStroke: '#bae6fd',
            connectionColor: '#7dd3fc',
            branchColors: ['#0284c7', '#0891b2', '#0d9488', '#059669', '#2563eb', '#4f46e5', '#7c3aed', '#0369a1'],
            shadow: 'rgba(14,165,233,0.1)',
        },
        sunset: {
            name: 'غروب',
            bg: '#fef7ee',
            centerText: '#ffffff',
            nodeBg: '#ffffff',
            nodeText: '#431407',
            nodeStroke: '#fed7aa',
            connectionColor: '#fdba74',
            branchColors: ['#ea580c', '#dc2626', '#e11d48', '#9333ea', '#c026d3', '#db2777', '#f59e0b', '#d97706'],
            shadow: 'rgba(234,88,12,0.1)',
        },
        forest: {
            name: 'غابة',
            bg: '#f0fdf4',
            centerText: '#ffffff',
            nodeBg: '#ffffff',
            nodeText: '#14532d',
            nodeStroke: '#bbf7d0',
            connectionColor: '#86efac',
            branchColors: ['#16a34a', '#0d9488', '#0891b2', '#059669', '#65a30d', '#4d7c0f', '#15803d', '#047857'],
            shadow: 'rgba(22,163,74,0.1)',
        },
        dark: {
            name: 'داكن',
            bg: '#0f172a',
            centerText: '#ffffff',
            nodeBg: '#1e293b',
            nodeText: '#e2e8f0',
            nodeStroke: '#334155',
            connectionColor: '#475569',
            branchColors: ['#34d399', '#60a5fa', '#fbbf24', '#f87171', '#a78bfa', '#f472b6', '#22d3ee', '#fb923c'],
            shadow: 'rgba(0,0,0,0.3)',
        }
    };

    const SVG_NS = 'http://www.w3.org/2000/svg';

    // ==========================================
    // 2. ثوابت التخطيط (Layout Constants)
    // ==========================================
    const LAYOUT = {
        CENTER: { fontSize: 15, lineHeight: 20, fontWeight: 'bold', maxTextW: 180, padX: 30, padY: 22, minW: 160, minH: 64 },
        BRANCH: { fontSize: 12.5, lineHeight: 17, fontWeight: '600', maxTextW: 130, padX: 35, padY: 14, minW: 120, minH: 46 },
        SUB: { fontSize: 11, lineHeight: 15, fontWeight: '500', maxTextW: 105, padX: 22, padY: 12, minW: 90, minH: 36 },
        CONCEPT: { fontSize: 11.5, lineHeight: 15, fontWeight: '600', maxTextW: 110, padX: 22, padY: 18, minW: 100, minH: 54 },
    };

    // ==========================================
    // 3. محرك قياس النصوص (Text Measurement Engine)
    // ==========================================
    const TextEngine = (() => {
        let _canvas = null;
        let _ctx = null;
        const _cache = new Map();
        const FONT_FAMILY = 'Cairo, Tajawal, sans-serif';

        function getCtx() {
            if (!_canvas) {
                _canvas = document.createElement('canvas');
                _ctx = _canvas.getContext('2d');
            }
            return _ctx;
        }

        /**
         * Measure text width using Canvas 2D API
         * Adds safety margin for Arabic text rendering differences
         */
        function measure(text, fontSize, fontWeight) {
            if (!text) return 0;
            fontWeight = fontWeight || 'normal';
            const key = `${text}|${fontSize}|${fontWeight}`;
            if (_cache.has(key)) return _cache.get(key);

            const ctx = getCtx();
            ctx.font = `${fontWeight} ${fontSize}px ${FONT_FAMILY}`;
            // 12% safety margin for Arabic text (wider than Latin)
            const width = ctx.measureText(text).width * 1.12;
            // Evict oldest entries when cache exceeds limit
            if (_cache.size > 2000) {
                const firstKey = _cache.keys().next().value;
                _cache.delete(firstKey);
            }
            _cache.set(key, width);
            return width;
        }

        /**
         * Wrap text into lines that fit within maxWidth
         */
        function wrapLines(text, maxWidth, fontSize, fontWeight) {
            if (!text || !text.trim()) return [];
            fontWeight = fontWeight || 'normal';
            const words = text.trim().split(/\s+/);
            if (words.length === 0) return [];

            const lines = [];
            let currentLine = words[0];

            for (let i = 1; i < words.length; i++) {
                const testLine = currentLine + ' ' + words[i];
                if (measure(testLine, fontSize, fontWeight) > maxWidth) {
                    lines.push(currentLine);
                    currentLine = words[i];
                } else {
                    currentLine = testLine;
                }
            }
            lines.push(currentLine);
            return lines;
        }

        /**
         * Calculate node dimensions based on text content
         * Returns { w, h, lines[], textW, textH }
         */
        function calcSize(text, cfg) {
            const lines = wrapLines(text || '', cfg.maxTextW, cfg.fontSize, cfg.fontWeight);
            const longestW = lines.reduce((max, line) =>
                Math.max(max, measure(line, cfg.fontSize, cfg.fontWeight)), 0);
            const textH = Math.max(lines.length, 1) * cfg.lineHeight;
            return {
                w: Math.max(longestW + cfg.padX * 2, cfg.minW),
                h: Math.max(textH + cfg.padY * 2, cfg.minH),
                lines,
                textW: longestW,
                textH
            };
        }

        return { measure, wrapLines, calcSize, FONT_FAMILY };
    })();

    // ==========================================
    // 4. أدوات SVG (SVG Utilities)
    // ==========================================
    const svg = {
        create(tag, attrs) {
            attrs = attrs || {};
            const el = document.createElementNS(SVG_NS, tag);
            for (const [k, v] of Object.entries(attrs)) {
                if (v !== undefined && v !== null) {
                    el.setAttribute(k, String(v));
                }
            }
            return el;
        },

        text(x, y, content, attrs) {
            attrs = attrs || {};
            const el = svg.create('text', {
                x, y,
                'text-anchor': 'middle',
                'dominant-baseline': 'central',
                'font-family': TextEngine.FONT_FAMILY,
                ...attrs
            });
            el.textContent = content || '';
            return el;
        },

        /**
         * Wrap text into multi-line SVG text
         * Returns { element: SVGGElement, height: number, lineCount: number }
         */
        wrapText(x, y, content, maxWidth, lineHeight, attrs) {
            attrs = attrs || {};
            const fontSize = parseFloat(attrs['font-size'] || 14);
            const fontWeight = attrs['font-weight'] || 'normal';
            const lines = TextEngine.wrapLines(content || '', maxWidth, fontSize, fontWeight);

            const g = svg.create('g');
            if (lines.length === 0) {
                return { element: g, height: 0, lineCount: 0 };
            }

            const totalHeight = lines.length * lineHeight;
            const startY = y - totalHeight / 2 + lineHeight / 2;

            lines.forEach((line, i) => {
                g.appendChild(svg.text(x, startY + i * lineHeight, line, attrs));
            });

            return { element: g, height: totalHeight, lineCount: lines.length };
        },

        roundRect(x, y, w, h, rx, attrs) {
            attrs = attrs || {};
            return svg.create('rect', { x, y, width: w, height: h, rx, ...attrs });
        },

        circle(cx, cy, r, attrs) {
            attrs = attrs || {};
            return svg.create('circle', { cx, cy, r, ...attrs });
        },

        path(d, attrs) {
            attrs = attrs || {};
            return svg.create('path', { d, fill: 'none', ...attrs });
        },

        line(x1, y1, x2, y2, attrs) {
            attrs = attrs || {};
            return svg.create('line', { x1, y1, x2, y2, ...attrs });
        },

        group(attrs) {
            attrs = attrs || {};
            return svg.create('g', attrs);
        },

        defs() {
            return svg.create('defs');
        },

        linearGradient(id, colors, attrs) {
            attrs = attrs || {};
            const grad = svg.create('linearGradient', {
                id, x1: '0%', y1: '0%', x2: '100%', y2: '100%', ...attrs
            });
            const maxIdx = Math.max(colors.length - 1, 1);
            colors.forEach((c, i) => {
                grad.appendChild(svg.create('stop', {
                    offset: `${(i / maxIdx) * 100}%`,
                    'stop-color': c
                }));
            });
            return grad;
        },

        /**
         * Create drop shadow filter using feGaussianBlur (universal browser support)
         */
        filter(id, blur) {
            blur = blur || 4;
            const f = svg.create('filter', {
                id, x: '-25%', y: '-25%', width: '150%', height: '150%'
            });
            const feFlood = svg.create('feFlood', {
                'flood-color': 'rgba(0,0,0,0.1)', 'flood-opacity': 1, result: 'flood'
            });
            const feComp = svg.create('feComposite', {
                in: 'flood', in2: 'SourceGraphic', operator: 'in', result: 'shadow'
            });
            const feBlur = svg.create('feGaussianBlur', {
                in: 'shadow', stdDeviation: blur, result: 'blur'
            });
            const feOff = svg.create('feOffset', {
                in: 'blur', dx: 0, dy: 2, result: 'offsetBlur'
            });
            const feMerge = svg.create('feMerge');
            feMerge.appendChild(svg.create('feMergeNode', { in: 'offsetBlur' }));
            feMerge.appendChild(svg.create('feMergeNode', { in: 'SourceGraphic' }));
            f.appendChild(feFlood);
            f.appendChild(feComp);
            f.appendChild(feBlur);
            f.appendChild(feOff);
            f.appendChild(feMerge);
            return f;
        }
    };

    // ==========================================
    // 4.5 محرك الأشكال (Shape Engine)
    // ==========================================
    const SHAPES = {
        rounded_rect: { name: 'مستطيل مدور', icon: '▭' },
        pill: { name: 'كبسولة', icon: '💊' },
        hexagon: { name: 'سداسي', icon: '⬡' },
        diamond: { name: 'معين', icon: '◇' },
        ellipse: { name: 'بيضاوي', icon: '⬭' },
        cloud: { name: 'سحابة', icon: '☁' },
        octagon: { name: 'مثمن', icon: '⬣' },
    };

    const ShapeEngine = {
        /**
         * Create an SVG shape element
         * @param {string} shape - shape name
         * @param {number} cx - center X
         * @param {number} cy - center Y
         * @param {number} w - width
         * @param {number} h - height
         * @param {object} attrs - SVG attributes
         */
        create(shape, cx, cy, w, h, attrs) {
            attrs = attrs || {};
            switch (shape) {
                case 'hexagon': return this.hexagon(cx, cy, w, h, attrs);
                case 'diamond': return this.diamond(cx, cy, w, h, attrs);
                case 'ellipse': return this.ellipseShape(cx, cy, w, h, attrs);
                case 'cloud': return this.cloud(cx, cy, w, h, attrs);
                case 'octagon': return this.octagon(cx, cy, w, h, attrs);
                case 'pill': return svg.roundRect(cx - w / 2, cy - h / 2, w, h, h / 2, attrs);
                default: return svg.roundRect(cx - w / 2, cy - h / 2, w, h, 14, attrs);
            }
        },

        hexagon(cx, cy, w, h, attrs) {
            const hw = w / 2, hh = h / 2;
            const inset = w * 0.22;
            const pts = [
                `${cx - hw + inset},${cy - hh}`,
                `${cx + hw - inset},${cy - hh}`,
                `${cx + hw},${cy}`,
                `${cx + hw - inset},${cy + hh}`,
                `${cx - hw + inset},${cy + hh}`,
                `${cx - hw},${cy}`
            ].join(' ');
            return svg.create('polygon', { points: pts, ...attrs });
        },

        diamond(cx, cy, w, h, attrs) {
            const hw = w / 2 + 10, hh = h / 2 + 10;
            const pts = [
                `${cx},${cy - hh}`,
                `${cx + hw},${cy}`,
                `${cx},${cy + hh}`,
                `${cx - hw},${cy}`
            ].join(' ');
            return svg.create('polygon', { points: pts, ...attrs });
        },

        ellipseShape(cx, cy, w, h, attrs) {
            return svg.create('ellipse', { cx, cy, rx: w / 2 + 6, ry: h / 2 + 6, ...attrs });
        },

        cloud(cx, cy, w, h, attrs) {
            const hw = w / 2 * 1.15, hh = h / 2 * 1.15;
            const d = `M ${cx - hw * 0.5} ${cy + hh}` +
                ` C ${cx - hw * 1.1} ${cy + hh}, ${cx - hw * 1.1} ${cy - hh * 0.2}, ${cx - hw * 0.5} ${cy - hh * 0.5}` +
                ` C ${cx - hw * 0.5} ${cy - hh * 1.2}, ${cx + hw * 0.1} ${cy - hh * 1.2}, ${cx + hw * 0.2} ${cy - hh * 0.5}` +
                ` C ${cx + hw * 0.5} ${cy - hh * 1.1}, ${cx + hw * 1.1} ${cy - hh * 0.3}, ${cx + hw * 0.8} ${cy + hh * 0.1}` +
                ` C ${cx + hw * 1.15} ${cy + hh * 0.8}, ${cx + hw * 0.5} ${cy + hh * 1.05}, ${cx + hw * 0.2} ${cy + hh} Z`;
            return svg.create('path', { d, ...attrs });
        },

        octagon(cx, cy, w, h, attrs) {
            const hw = w / 2, hh = h / 2;
            const c = Math.min(hw, hh) * 0.38;
            const pts = [
                `${cx - hw + c},${cy - hh}`,
                `${cx + hw - c},${cy - hh}`,
                `${cx + hw},${cy - hh + c}`,
                `${cx + hw},${cy + hh - c}`,
                `${cx + hw - c},${cy + hh}`,
                `${cx - hw + c},${cy + hh}`,
                `${cx - hw},${cy + hh - c}`,
                `${cx - hw},${cy - hh + c}`
            ].join(' ');
            return svg.create('polygon', { points: pts, ...attrs });
        }
    };

    // ==========================================
    // 5. محرك المنحنيات (Curve Engine)
    // ==========================================
    function curvePath(x1, y1, x2, y2, curvature) {
        curvature = curvature || 0.4;
        const dx = x2 - x1;
        const cx1 = x1 + dx * curvature;
        const cx2 = x2 - dx * curvature;
        return `M ${x1} ${y1} C ${cx1} ${y1}, ${cx2} ${y2}, ${x2} ${y2}`;
    }

    /**
     * Smooth S-curve path that goes through midpoint
     */
    function smoothCurve(x1, y1, x2, y2) {
        const midX = (x1 + x2) / 2;
        const midY = (y1 + y2) / 2;
        return `M ${x1} ${y1} C ${midX} ${y1}, ${midX} ${y2}, ${x2} ${y2}`;
    }

    /**
     * Calculate edge intersection point for rectangle
     * Line from (cx,cy) toward (tx,ty), returns where it hits the rect edge
     */
    function rectEdgePoint(cx, cy, w, h, tx, ty) {
        const dx = tx - cx;
        const dy = ty - cy;
        if (dx === 0 && dy === 0) return { x: cx, y: cy };

        const hw = w / 2, hh = h / 2;
        const absDx = Math.abs(dx) || 0.001;
        const absDy = Math.abs(dy) || 0.001;
        const sx = hw / absDx;
        const sy = hh / absDy;
        const s = Math.min(sx, sy);

        return { x: cx + dx * s, y: cy + dy * s };
    }

    // ==========================================
    // 6. حل التداخلات (Collision Resolution)
    // ==========================================
    /**
     * Iteratively push overlapping nodes apart
     * Skips the center node (index 0)
     */
    function resolveCollisions(nodes, iterations) {
        iterations = iterations || 8;
        const gap = 18;

        for (let iter = 0; iter < iterations; iter++) {
            let moved = false;
            for (let i = 1; i < nodes.length; i++) {
                for (let j = i + 1; j < nodes.length; j++) {
                    const a = nodes[i], b = nodes[j];
                    const aw = (a.w || 100), ah = (a.h || 50);
                    const bw = (b.w || 100), bh = (b.h || 50);

                    // Axis-aligned bounding box overlap check
                    const overlapX = (aw / 2 + bw / 2 + gap) - Math.abs(a.x - b.x);
                    const overlapY = (ah / 2 + bh / 2 + gap) - Math.abs(a.y - b.y);

                    if (overlapX > 0 && overlapY > 0) {
                        // Push apart — prefer the axis with smaller overlap
                        const pushFactor = 0.55;
                        if (overlapX < overlapY) {
                            const sign = a.x >= b.x ? 1 : -1;
                            a.x += overlapX * pushFactor * sign;
                            b.x -= overlapX * pushFactor * sign;
                        } else {
                            const sign = a.y >= b.y ? 1 : -1;
                            a.y += overlapY * pushFactor * sign;
                            b.y -= overlapY * pushFactor * sign;
                        }
                        moved = true;
                    }
                }
            }
            if (!moved) break;
        }
    }

    // ==========================================
    // 7. تخطيط الخريطة الذهنية التكيُّفي
    //    Adaptive Mind Map Layout
    // ==========================================
    function calculateMindMapLayout(data) {
        const branches = data.branches || [];
        const n = branches.length;

        // Default center node for empty data
        const centerSize = TextEngine.calcSize(data.central_node || '', LAYOUT.CENTER);
        if (n === 0) {
            return {
                nodes: [{
                    type: 'center', x: 0, y: 0,
                    text: data.central_node || '', icon: data.central_icon || '🎯',
                    color: data.central_color || '#667eea',
                    w: centerSize.w, h: centerSize.h, lines: centerSize.lines
                }],
                bounds: { x: -centerSize.w, y: -centerSize.h, w: centerSize.w * 2, h: centerSize.h * 2 }
            };
        }

        // ---- Step 1: Measure all texts ----
        const branchMeasures = branches.map(b =>
            TextEngine.calcSize(b.title || '', LAYOUT.BRANCH)
        );
        const subMeasures = branches.map(b =>
            (b.sub_branches || []).map(s =>
                TextEngine.calcSize(s.title || '', LAYOUT.SUB)
            )
        );

        // ---- Step 2: Calculate optimal branch ring radius ----
        const maxBranchW = Math.max(...branchMeasures.map(m => m.w));
        const maxBranchH = Math.max(...branchMeasures.map(m => m.h));
        const branchDiag = Math.sqrt(maxBranchW * maxBranchW + maxBranchH * maxBranchH);

        const branchGap = 55;
        const minCirc = n * (branchDiag + branchGap);
        const branchRadius = Math.max(
            minCirc / (2 * Math.PI),
            centerSize.w / 2 + maxBranchW / 2 + 70,
            220
        );

        // ---- Step 3: Position all nodes ----
        const nodes = [];
        const centerX = 0, centerY = 0;

        // Center node
        nodes.push({
            type: 'center', x: centerX, y: centerY,
            text: data.central_node || '', icon: data.central_icon || '🎯',
            color: data.central_color || '#667eea',
            w: centerSize.w, h: centerSize.h, lines: centerSize.lines
        });

        const angleStep = (2 * Math.PI) / n;
        const startAngle = -Math.PI / 2;

        branches.forEach((branch, i) => {
            const angle = startAngle + i * angleStep;
            const bx = centerX + branchRadius * Math.cos(angle);
            const by = centerY + branchRadius * Math.sin(angle);
            const color = branch.color || THEMES.modern.branchColors[i % THEMES.modern.branchColors.length];
            const bm = branchMeasures[i];

            nodes.push({
                type: 'branch', x: bx, y: by,
                text: branch.title || '', icon: branch.icon || '📌',
                color, parentIndex: 0, angle,
                shape: branch.shape || 'rounded_rect',
                branchIdx: i,
                w: bm.w, h: bm.h, lines: bm.lines
            });

            const parentIdx = nodes.length - 1;
            const subs = branch.sub_branches || [];

            if (subs.length > 0) {
                const subMs = subMeasures[i];
                const maxSubH = Math.max(...subMs.map(s => s.h));
                const maxSubW = Math.max(...subMs.map(s => s.w));

                // Sub-radius: ensure distance from branch to sub is enough
                const subRadius = Math.max(
                    bm.h / 2 + maxSubH / 2 + 55,
                    maxSubW * 0.8 + 40,
                    110
                );

                // Calculate needed angular spread
                // Each sub needs enough angular separation to avoid overlap
                const minSubAngGap = subs.length > 1 ?
                    Math.atan2(maxSubH + 22, subRadius) * 2 : 0;
                const neededSpread = minSubAngGap * Math.max(subs.length - 1, 1);
                const maxSpread = angleStep * 0.82;

                // If subs don't fit, increase sub-radius
                let actualSubRadius = subRadius;
                if (neededSpread > maxSpread && subs.length > 1) {
                    const halfAngle = maxSpread / (2 * (subs.length - 1));
                    const sinHalf = Math.sin(halfAngle);
                    if (sinHalf > 0.01) {
                        actualSubRadius = Math.max(subRadius, (maxSubH + 22) / (2 * sinHalf));
                    }
                }

                const spread = Math.min(neededSpread, maxSpread);
                const subStartAngle = angle - spread / 2;
                const subStep = subs.length > 1 ? spread / (subs.length - 1) : 0;

                subs.forEach((sub, j) => {
                    const sAngle = subs.length === 1 ? angle : subStartAngle + j * subStep;
                    const sx = bx + actualSubRadius * Math.cos(sAngle);
                    const sy = by + actualSubRadius * Math.sin(sAngle);
                    const sm = subMs[j];

                    nodes.push({
                        type: 'sub', x: sx, y: sy,
                        text: sub.title || '', details: sub.details || '',
                        icon: sub.icon || '📝', color,
                        parentIndex: parentIdx, angle: sAngle,
                        shape: sub.shape || 'rounded_rect',
                        branchIdx: i, subIdx: j,
                        w: sm.w, h: sm.h, lines: sm.lines
                    });
                });
            }
        });

        // ---- Step 4: Collision resolution ----
        resolveCollisions(nodes, 10);

        // ---- Step 5: Calculate bounding box ----
        let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
        nodes.forEach(nd => {
            const hw = (nd.w || 150) / 2;
            const hh = (nd.h || 60) / 2;
            minX = Math.min(minX, nd.x - hw);
            maxX = Math.max(maxX, nd.x + hw);
            minY = Math.min(minY, nd.y - hh);
            maxY = Math.max(maxY, nd.y + hh);
        });

        const pad = 70;
        return {
            nodes,
            bounds: {
                x: minX - pad,
                y: minY - pad,
                w: (maxX - minX) + pad * 2,
                h: (maxY - minY) + pad * 2
            }
        };
    }

    // ==========================================
    // 8. رسم الخريطة الذهنية (Mind Map Renderer)
    // ==========================================
    function renderMindMapSVG(container, data, options) {
        options = options || {};
        const {
            theme = 'modern',
            animate = true,
            interactive = true
        } = options;

        const t = THEMES[theme] || THEMES.modern;
        const { nodes, bounds } = calculateMindMapLayout(data);

        if (nodes.length === 0) return null;

        const vw = Math.max(bounds.w, 700);
        const vh = Math.max(bounds.h, 450);

        // Create SVG with auto-fit viewBox
        const svgEl = svg.create('svg', {
            width: '100%',
            height: '100%',
            viewBox: `${bounds.x} ${bounds.y} ${vw} ${vh}`,
            class: 'eduvisual-mindmap',
            style: 'direction: ltr',
            preserveAspectRatio: 'xMidYMid meet'
        });

        // ---- Defs ----
        const defs = svg.defs();
        const centerColor = data.central_color || '#667eea';
        defs.appendChild(svg.linearGradient('centerGrad', [centerColor, shadeColor(centerColor, -30)]));

        const branchNodes = nodes.filter(nd => nd.type === 'branch');
        branchNodes.forEach((nd, i) => {
            defs.appendChild(svg.linearGradient(`branchGrad${i}`, [nd.color, shadeColor(nd.color, -20)]));
        });

        defs.appendChild(svg.filter('shadowSm', 2));
        defs.appendChild(svg.filter('shadowMd', 4));
        defs.appendChild(svg.filter('shadowLg', 6));

        // Glow filter for center node
        const glowFilter = svg.create('filter', { id: 'glow', x: '-50%', y: '-50%', width: '200%', height: '200%' });
        const feGauss = svg.create('feGaussianBlur', { stdDeviation: 10, result: 'blur' });
        const feMerge = svg.create('feMerge');
        feMerge.appendChild(svg.create('feMergeNode', { in: 'blur' }));
        feMerge.appendChild(svg.create('feMergeNode', { in: 'SourceGraphic' }));
        glowFilter.appendChild(feGauss);
        glowFilter.appendChild(feMerge);
        defs.appendChild(glowFilter);

        svgEl.appendChild(defs);

        // ---- Background ----
        svgEl.appendChild(svg.roundRect(bounds.x, bounds.y, vw, vh, 20, {
            fill: t.bg, class: 'mindmap-bg'
        }));

        const mainGroup = svg.group({ class: 'mindmap-content' });

        // ---- Connections (drawn below nodes) ----
        const connectionsGroup = svg.group({ class: 'connections' });
        nodes.forEach((node, i) => {
            if (node.parentIndex !== undefined) {
                const parent = nodes[node.parentIndex];
                const isBranch = node.type === 'branch';

                // Calculate edge points for cleaner connections
                const fromEdge = rectEdgePoint(parent.x, parent.y, parent.w, parent.h, node.x, node.y);
                const toEdge = rectEdgePoint(node.x, node.y, node.w, node.h, parent.x, parent.y);

                const pathD = smoothCurve(fromEdge.x, fromEdge.y, toEdge.x, toEdge.y);

                connectionsGroup.appendChild(svg.path(pathD, {
                    stroke: isBranch ? node.color + 'BB' : node.color + '55',
                    'stroke-width': isBranch ? 3 : 2,
                    'stroke-linecap': 'round',
                    class: animate ? 'animate-draw' : '',
                    style: animate ? `animation-delay: ${i * 0.04}s` : ''
                }));
            }
        });
        mainGroup.appendChild(connectionsGroup);

        // ---- Nodes ----
        const nodesGroup = svg.group({ class: 'nodes' });

        nodes.forEach((node, i) => {
            const nodeGroup = svg.group({
                class: `node node-${node.type} ${animate ? 'animate-pop' : ''} ${interactive ? 'interactive' : ''}`,
                style: animate ? `animation-delay: ${0.1 + i * 0.05}s` : '',
                'data-index': i
            });

            const w = node.w, h = node.h;

            if (node.type === 'center') {
                const rx = h / 2; // pill shape

                // Outer glow ring
                nodeGroup.appendChild(svg.roundRect(node.x - w / 2 - 5, node.y - h / 2 - 5, w + 10, h + 10, rx + 5, {
                    fill: centerColor + '18', class: 'center-glow'
                }));

                // Main shape
                nodeGroup.appendChild(svg.roundRect(node.x - w / 2, node.y - h / 2, w, h, rx, {
                    fill: 'url(#centerGrad)', filter: 'url(#shadowLg)', class: 'center-bg'
                }));

                // Icon + Text
                const hasMultiLine = node.lines && node.lines.length > 1;
                const iconY = hasMultiLine ? node.y - h / 4 : node.y - 10;
                nodeGroup.appendChild(svg.text(node.x, iconY, node.icon, {
                    'font-size': 20, fill: t.centerText
                }));

                const textY = hasMultiLine ? node.y + 6 : node.y + 13;
                const wrapped = svg.wrapText(node.x, textY, node.text, w - 30, LAYOUT.CENTER.lineHeight, {
                    'font-size': LAYOUT.CENTER.fontSize, 'font-weight': 'bold', fill: t.centerText
                });
                nodeGroup.appendChild(wrapped.element);

            } else if (node.type === 'branch') {
                const nodeShape = node.shape || 'rounded_rect';

                // Main shape (dynamic)
                const branchBg = ShapeEngine.create(nodeShape, node.x, node.y, w, h, {
                    fill: t.nodeBg, stroke: node.color, 'stroke-width': 2.5,
                    filter: 'url(#shadowMd)', class: 'branch-bg'
                });
                nodeGroup.appendChild(branchBg);

                // Color accent bar only for rectangular shapes
                if (nodeShape === 'rounded_rect' || nodeShape === 'pill') {
                    nodeGroup.appendChild(svg.create('rect', {
                        x: node.x + w / 2 - 6, y: node.y - h / 2 + 4,
                        width: 4, height: h - 8, rx: 2,
                        fill: node.color
                    }));
                }

                // Icon
                nodeGroup.appendChild(svg.text(node.x - w / 2 + 22, node.y, node.icon, {
                    'font-size': 15
                }));

                // Text
                const wrapped = svg.wrapText(node.x + 5, node.y, node.text, w - 60, LAYOUT.BRANCH.lineHeight, {
                    'font-size': LAYOUT.BRANCH.fontSize, 'font-weight': '600', fill: node.color
                });
                nodeGroup.appendChild(wrapped.element);

            } else if (node.type === 'sub') {
                const subShape = node.shape || 'rounded_rect';

                // Main shape (dynamic)
                const subBg = ShapeEngine.create(subShape, node.x, node.y, w, h, {
                    fill: t.nodeBg, stroke: node.color + '40', 'stroke-width': 1.5,
                    filter: 'url(#shadowSm)', class: 'sub-bg'
                });
                nodeGroup.appendChild(subBg);

                // Color dot top-right (only for rect-like shapes)
                if (subShape === 'rounded_rect' || subShape === 'pill' || subShape === 'octagon') {
                    nodeGroup.appendChild(svg.circle(node.x + w / 2 - 11, node.y - h / 2 + 11, 3.5, {
                        fill: node.color
                    }));
                }

                // Icon
                nodeGroup.appendChild(svg.text(node.x - w / 2 + 16, node.y, node.icon, {
                    'font-size': 12
                }));

                // Text
                const wrapped = svg.wrapText(node.x + 5, node.y, node.text, w - 46, LAYOUT.SUB.lineHeight, {
                    'font-size': LAYOUT.SUB.fontSize, 'font-weight': '500', fill: t.nodeText
                });
                nodeGroup.appendChild(wrapped.element);

                // Tooltip for details
                if (node.details) {
                    const title = svg.create('title');
                    title.textContent = node.details;
                    nodeGroup.appendChild(title);
                }
            }

            nodesGroup.appendChild(nodeGroup);
        });

        mainGroup.appendChild(nodesGroup);
        svgEl.appendChild(mainGroup);

        // Mount
        container.innerHTML = '';
        container.appendChild(svgEl);

        if (interactive) {
            enableZoomPan(svgEl, mainGroup);
            enableTooltips(container, svgEl, nodes);
        }

        return svgEl;
    }

    // ==========================================
    // 9-10. مخطط فِن (Venn Diagram Renderer)
    // ==========================================
    function renderVennDiagramSVG(container, data, options) {
        options = options || {};
        const { theme = 'modern', animate = true } = options;
        const t = THEMES[theme] || THEMES.modern;

        const setA = data.set_a || {};
        const setB = data.set_b || {};
        const inter = data.intersection || {};
        const itemsA = setA.items || [];
        const itemsB = setB.items || [];
        const itemsShared = inter.items || [];

        if (itemsA.length === 0 && itemsB.length === 0 && itemsShared.length === 0) return null;

        const width = 900, height = 560;
        const cx = width / 2, cy = height / 2 + 20;
        const r = 190;           // circle radius
        const overlap = 70;      // overlap distance
        const cxA = cx - overlap, cxB = cx + overlap;

        const colorA = setA.color || '#3b82f6';
        const colorB = setB.color || '#10b981';

        const svgEl = svg.create('svg', {
            width: '100%', height: '100%',
            viewBox: `0 0 ${width} ${height}`,
            class: 'eduvisual-venn', style: 'direction:ltr',
            preserveAspectRatio: 'xMidYMid meet'
        });

        const defs = svg.defs();
        defs.appendChild(svg.filter('vshadow', 3));
        // Clip paths for text regions
        const clipA = svg.create('clipPath', { id: 'venn-clip-a' });
        clipA.appendChild(svg.create('circle', { cx: cxA, cy, r }));
        defs.appendChild(clipA);
        const clipB = svg.create('clipPath', { id: 'venn-clip-b' });
        clipB.appendChild(svg.create('circle', { cx: cxB, cy, r }));
        defs.appendChild(clipB);
        svgEl.appendChild(defs);

        // Background
        svgEl.appendChild(svg.roundRect(0, 0, width, height, 16, { fill: t.bg }));
        const mainG = svg.group({ class: 'venn-content' });

        // Circle A (left) — semi-transparent
        mainG.appendChild(svg.create('circle', {
            cx: cxA, cy, r,
            fill: colorA, opacity: 0.18,
            stroke: colorA, 'stroke-width': 2.5,
            class: animate ? 'animate-pop' : ''
        }));

        // Circle B (right) — semi-transparent
        mainG.appendChild(svg.create('circle', {
            cx: cxB, cy, r,
            fill: colorB, opacity: 0.18,
            stroke: colorB, 'stroke-width': 2.5,
            class: animate ? 'animate-pop' : '',
            style: animate ? 'animation-delay: 0.1s' : ''
        }));

        // Label A (top-left)
        const labelAX = cxA - r + 20;
        mainG.appendChild(svg.text(cxA - overlap / 2 - 30, cy - r - 18, (setA.icon || '🔵') + ' ' + (setA.label || 'A'), {
            'font-size': 15, 'font-weight': '700', fill: colorA, 'text-anchor': 'middle'
        }));

        // Label B (top-right)
        mainG.appendChild(svg.text(cxB + overlap / 2 + 30, cy - r - 18, (setB.icon || '🟢') + ' ' + (setB.label || 'B'), {
            'font-size': 15, 'font-weight': '700', fill: colorB, 'text-anchor': 'middle'
        }));

        // --- Render items in regions ---
        function renderItemList(items, startX, startY, maxWidth, color, cssClass, dataAttrBase) {
            const lineH = 26;
            items.forEach((item, i) => {
                const itemText = typeof item === 'string' ? item : (item.text || item.label || '');
                const y = startY + i * lineH;
                const g = svg.group({
                    class: `${cssClass} interactive ${animate ? 'animate-pop' : ''}`,
                    style: animate ? `animation-delay: ${0.2 + i * 0.06}s` : '',
                    [`data-${dataAttrBase}-index`]: i
                });
                // Bullet
                g.appendChild(svg.create('circle', {
                    cx: startX - 6, cy: y, r: 4,
                    fill: color, opacity: 0.7
                }));
                // Text
                g.appendChild(svg.text(startX + 4, y + 1, itemText, {
                    'font-size': 11, fill: t.nodeText, 'font-weight': '500',
                    'text-anchor': 'start'
                }));
                mainG.appendChild(g);
            });
        }

        // Items in A only (left region)
        const leftX = cxA - r + 30;
        const topY = cy - (itemsA.length * 26) / 2 + 13;
        renderItemList(itemsA, leftX, topY, r - overlap, colorA, 'venn-item-a', 'venn-a');

        // Items in B only (right region)
        const rightX = cxB + 10;
        const topYB = cy - (itemsB.length * 26) / 2 + 13;
        renderItemList(itemsB, rightX, topYB, r - overlap, colorB, 'venn-item-b', 'venn-b');

        // Items in intersection (middle region)
        const midX = cx - 40;
        const topYM = cy - (itemsShared.length * 26) / 2 + 13;
        const interColor = '#8b5cf6';
        itemsShared.forEach((item, i) => {
            const itemText = typeof item === 'string' ? item : (item.text || item.label || '');
            const y = topYM + i * 26;
            const g = svg.group({
                class: `venn-item-shared interactive ${animate ? 'animate-pop' : ''}`,
                style: animate ? `animation-delay: ${0.3 + i * 0.06}s` : '',
                'data-venn-shared-index': i
            });
            g.appendChild(svg.create('circle', {
                cx: midX - 6, cy: y, r: 4,
                fill: interColor, opacity: 0.9
            }));
            g.appendChild(svg.text(midX + 4, y + 1, itemText, {
                'font-size': 11.5, fill: t.nodeText, 'font-weight': '600',
                'text-anchor': 'start'
            }));
            mainG.appendChild(g);
        });

        // Intersection label
        mainG.appendChild(svg.text(cx, cy + r - 10, '∩ مشترك', {
            'font-size': 10, fill: interColor, 'font-weight': '600',
            'text-anchor': 'middle', opacity: 0.6
        }));

        svgEl.appendChild(mainG);
        container.innerHTML = '';
        container.appendChild(svgEl);
        enableZoomPan(svgEl, mainG);
        enableTooltips(container, svgEl, null);
        return svgEl;
    }

    // ==========================================
    // 10. رسم الخريطة المفاهيمية (Concept Map Renderer)
    //     ← LEGACY: kept for backward compatibility with old data
    // ==========================================
    function renderConceptMapSVG(container, data, options) {
        // Fallback: if old concept_maps data arrives, render as simple node list
        options = options || {};
        const { theme = 'modern', animate = true } = options;
        const t = THEMES[theme] || THEMES.modern;
        const nodesData = data.nodes || [];
        if (nodesData.length === 0) return null;

        const width = 800, height = 500;
        const svgEl = svg.create('svg', {
            width: '100%', height: '100%',
            viewBox: `0 0 ${width} ${height}`,
            class: 'eduvisual-conceptmap-legacy', style: 'direction:ltr',
            preserveAspectRatio: 'xMidYMid meet'
        });
        svgEl.appendChild(svg.roundRect(0, 0, width, height, 16, { fill: t.bg }));
        const mainG = svg.group({ class: 'concept-legacy-content' });

        const cols = Math.ceil(Math.sqrt(nodesData.length));
        const cellW = (width - 80) / cols;
        const cellH = 90;

        nodesData.forEach((nd, ni) => {
            const col = ni % cols, row = Math.floor(ni / cols);
            const x = 40 + col * cellW + cellW / 2;
            const y = 60 + row * cellH + cellH / 2;
            const color = '#3b82f6';
            const g = svg.group({ class: `concept-node interactive ${animate ? 'animate-pop' : ''}`, style: animate ? `animation-delay: ${ni * 0.08}s` : '' });
            g.appendChild(svg.roundRect(x - cellW / 2 + 10, y - 30, cellW - 20, 60, 12, { fill: t.nodeBg, stroke: color, 'stroke-width': 1.5 }));
            g.appendChild(svg.text(x, y - 8, nd.icon || '💡', { 'font-size': 16 }));
            g.appendChild(svg.text(x, y + 12, nd.label || '', { 'font-size': 11, fill: color, 'font-weight': '600' }));
            mainG.appendChild(g);
        });

        svgEl.appendChild(mainG);
        container.innerHTML = '';
        container.appendChild(svgEl);
        enableZoomPan(svgEl, mainG);
        return svgEl;
    }

    // ==========================================
    // 11. رسم الملخص البصري (Visual Summary Renderer)
    //     RTL-aware cards with proper text alignment
    // ==========================================
    function renderVisualSummarySVG(container, summaries, options) {
        options = options || {};
        const {
            theme = 'modern',
            animate = true
        } = options;

        const t = THEMES[theme] || THEMES.modern;
        if (!summaries || summaries.length === 0) return null;

        const width = 880;
        const cardLeft = 55;         // card starts here (x)
        const cardRight = width - 55; // card ends here (x)
        const cardW = cardRight - cardLeft;

        // RTL layout inside card:
        // |  [text area]  | [number] | [icon + color bar] |
        //  ^               ^           ^
        //  cardLeft+15     cardRight-80  cardRight-5
        const iconAreaRight = cardRight - 10;    // right edge, icon + color
        const numberX = cardRight - 72;          // number circle center
        const textLeft = cardLeft + 18;          // text area left edge
        const textRight = numberX - 20;          // text area right edge
        const textAreaW = textRight - textLeft;  // available text width
        const textCenterX = (textLeft + textRight) / 2;

        const cardPadTop = 15;
        const cardPadBottom = 15;

        // ---- Pre-calculate total height ----
        let totalH = 45;
        const sectionMeta = [];

        summaries.forEach((summary) => {
            totalH += 56; // section header + gap

            const pointsMeta = [];
            (summary.key_points || []).forEach((point) => {
                const titleLines = TextEngine.wrapLines(point.point || '', textAreaW, 13, '600');
                const titleH = Math.max(titleLines.length, 1) * 19;

                let explH = 0;
                if (point.explanation) {
                    const explLines = TextEngine.wrapLines(point.explanation, textAreaW, 11, 'normal');
                    explH = explLines.length * 16;
                }

                const cardH = cardPadTop + titleH + (explH > 0 ? 10 + explH : 0) + cardPadBottom;
                pointsMeta.push({ titleH, explH, cardH });
                totalH += cardH + 12;
            });

            sectionMeta.push({ points: pointsMeta });
            totalH += 18;
        });

        totalH = Math.max(totalH + 20, 200);

        // ---- Create SVG ----
        const svgEl = svg.create('svg', {
            width: '100%',
            viewBox: `0 0 ${width} ${totalH}`,
            class: 'eduvisual-summary',
            style: 'direction: ltr',
            preserveAspectRatio: 'xMidYMid meet'
        });

        const defs = svg.defs();
        defs.appendChild(svg.filter('sshadow', 2));
        svgEl.appendChild(defs);

        // Background
        svgEl.appendChild(svg.roundRect(0, 0, width, totalH, 16, { fill: t.bg }));

        let yPos = 35;

        summaries.forEach((summary, sIdx) => {
            const color = summary.color || t.branchColors[sIdx % t.branchColors.length];
            const meta = sectionMeta[sIdx];

            // ---- Section header ----
            const headerG = svg.group({
                class: animate ? 'animate-slide' : '',
                style: animate ? `animation-delay: ${sIdx * 0.15}s` : ''
            });

            headerG.appendChild(svg.roundRect(cardLeft, yPos, cardW, 46, 14, {
                fill: color, filter: 'url(#sshadow)', opacity: 0.95
            }));
            headerG.appendChild(svg.text(cardLeft + cardW / 2, yPos + 23,
                (summary.icon || '📋') + '  ' + (summary.title || ''),
                { 'font-size': 15, 'font-weight': '700', fill: '#ffffff' }
            ));

            svgEl.appendChild(headerG);
            yPos += 56;

            // ---- Key points ----
            (summary.key_points || []).forEach((point, pIdx) => {
                const pm = meta.points[pIdx];
                const cardH = pm.cardH;

                const pointG = svg.group({
                    class: `summary-point interactive ${animate ? 'animate-slide' : ''}`,
                    style: animate ? `animation-delay: ${sIdx * 0.15 + (pIdx + 1) * 0.06}s` : '',
                    'data-summary-index': sIdx,
                    'data-point-index': pIdx
                });

                // Card background
                pointG.appendChild(svg.roundRect(cardLeft, yPos, cardW, cardH, 12, {
                    fill: t.nodeBg, stroke: color + '30', 'stroke-width': 1.5,
                    filter: 'url(#sshadow)'
                }));

                // Color accent bar (right edge)
                pointG.appendChild(svg.roundRect(cardRight - 6, yPos + 6, 4, cardH - 12, 2, {
                    fill: color
                }));

                // Icon (right side)
                pointG.appendChild(svg.text(iconAreaRight - 26, yPos + cardH / 2, point.icon || '✅', {
                    'font-size': 18
                }));

                // Number circle (right side, before icon)
                pointG.appendChild(svg.circle(numberX, yPos + cardH / 2, 13, {
                    fill: color + '18', stroke: color, 'stroke-width': 1.5
                }));
                pointG.appendChild(svg.text(numberX, yPos + cardH / 2, String(pIdx + 1), {
                    'font-size': 11, 'font-weight': '700', fill: color
                }));

                // Title text (left-center area, centered alignment)
                const titleWrapped = svg.wrapText(
                    textCenterX,
                    yPos + cardPadTop + pm.titleH / 2,
                    point.point || '',
                    textAreaW,
                    19,
                    { 'font-size': 13, 'font-weight': '600', fill: t.nodeText }
                );
                pointG.appendChild(titleWrapped.element);

                // Explanation text
                if (point.explanation && pm.explH > 0) {
                    const explWrapped = svg.wrapText(
                        textCenterX,
                        yPos + cardPadTop + pm.titleH + 10 + pm.explH / 2,
                        point.explanation,
                        textAreaW,
                        16,
                        { 'font-size': 11, fill: t.nodeText, 'fill-opacity': 0.6 }
                    );
                    pointG.appendChild(explWrapped.element);
                }

                svgEl.appendChild(pointG);
                yPos += cardH + 12;
            });

            yPos += 18;
        });

        container.innerHTML = '';
        container.appendChild(svgEl);
        return svgEl;
    }

    // ==========================================
    // ==========================================
    // 11.1 خط زمني (Timeline Renderer)
    // ==========================================
    function renderTimelineSVG(container, data, options) {
        options = options || {};
        const { theme = 'modern', animate = true } = options;
        const t = THEMES[theme] || THEMES.modern;
        const events = data.events || [];
        if (events.length === 0) return null;

        const n = events.length;
        const eventSpacing = 140;
        const width = Math.max(850, n * eventSpacing + 200);
        const height = 420;
        const lineY = height / 2;
        const startX = 80, endX = width - 80;
        const lineLen = endX - startX;

        const svgEl = svg.create('svg', {
            width: '100%', height: '100%',
            viewBox: `0 0 ${width} ${height}`,
            class: 'eduvisual-timeline', style: 'direction:ltr',
            preserveAspectRatio: 'xMidYMid meet'
        });
        const defs = svg.defs();
        defs.appendChild(svg.filter('tlshadow', 3));

        // Arrow marker for timeline
        const marker = svg.create('marker', {
            id: 'tl-arrow', viewBox: '0 0 10 7', refX: 10, refY: 3.5,
            markerWidth: 10, markerHeight: 8, orient: 'auto'
        });
        marker.appendChild(svg.create('polygon', {
            points: '0 0, 10 3.5, 0 7', fill: t.connectionColor
        }));
        defs.appendChild(marker);
        svgEl.appendChild(defs);

        // Background
        svgEl.appendChild(svg.roundRect(0, 0, width, height, 16, { fill: t.bg }));
        const mainG = svg.group({ class: 'timeline-content' });

        // Main timeline line with arrow
        mainG.appendChild(svg.path(`M ${startX - 20} ${lineY} L ${endX + 20} ${lineY}`, {
            stroke: t.connectionColor, 'stroke-width': 3, 'stroke-linecap': 'round',
            'marker-end': 'url(#tl-arrow)',
            class: animate ? 'animate-draw' : ''
        }));

        // Events
        const spacing = lineLen / Math.max(n - 1, 1);

        events.forEach((evt, i) => {
            const x = n === 1 ? (startX + endX) / 2 : startX + i * spacing;
            const isTop = i % 2 === 0;
            const dir = isTop ? -1 : 1;
            const color = evt.color || t.branchColors[i % t.branchColors.length];

            const evtG = svg.group({
                class: `timeline-event interactive ${animate ? 'animate-pop' : ''}`,
                style: animate ? `animation-delay: ${0.15 + i * 0.1}s` : '',
                'data-event-index': i
            });

            // Dot on timeline
            evtG.appendChild(svg.create('circle', {
                cx: x, cy: lineY, r: 8,
                fill: color, stroke: t.bg, 'stroke-width': 3
            }));

            // Connector line
            const cardY = lineY + dir * 50;
            evtG.appendChild(svg.create('line', {
                x1: x, y1: lineY + dir * 10, x2: x, y2: cardY,
                stroke: color, 'stroke-width': 2, 'stroke-dasharray': '4,3',
                opacity: 0.6
            }));

            // Event card
            const cardW = 120, cardH = 80;
            const cardTop = isTop ? cardY - cardH : cardY;
            evtG.appendChild(svg.roundRect(x - cardW / 2, cardTop, cardW, cardH, 12, {
                fill: t.nodeBg, stroke: color, 'stroke-width': 1.5,
                filter: 'url(#tlshadow)'
            }));

            // Color accent at top/bottom of card
            const accentY = isTop ? cardTop + cardH - 4 : cardTop;
            evtG.appendChild(svg.create('rect', {
                x: x - cardW / 2 + 10, y: accentY, width: cardW - 20, height: 3, rx: 1.5,
                fill: color
            }));

            // Icon
            const iconY = cardTop + 20;
            evtG.appendChild(svg.text(x, iconY, evt.icon || '📅', { 'font-size': 16 }));

            // Label (wrapped)
            const labelY = cardTop + 40;
            const wrapped = svg.wrapText(x, labelY, evt.label || '', cardW - 16, 14, {
                'font-size': 10.5, 'font-weight': '600', fill: t.nodeText
            });
            evtG.appendChild(wrapped.element);

            // Period badge below/above card
            if (evt.period) {
                const badgeY = isTop ? cardTop - 16 : cardTop + cardH + 6;
                const badgeW = Math.min(TextEngine.measure(evt.period, 9, '600') + 14, 110);
                evtG.appendChild(svg.roundRect(x - badgeW / 2, badgeY, badgeW, 18, 9, {
                    fill: color, opacity: 0.85
                }));
                evtG.appendChild(svg.text(x, badgeY + 9, evt.period, {
                    'font-size': 9, fill: '#fff', 'font-weight': '600'
                }));
            }

            mainG.appendChild(evtG);
        });

        svgEl.appendChild(mainG);
        container.innerHTML = '';
        container.appendChild(svgEl);
        enableZoomPan(svgEl, mainG);
        enableTooltips(container, svgEl, null);
        return svgEl;
    }

    // ==========================================
    // 11.1b خريطة عظمة السمكة (Ishikawa / Fishbone Diagram Renderer)
    // ==========================================
    function renderFishboneSVG(container, data, options) {
        options = options || {};
        const { theme = 'modern', animate = true } = options;
        const t = THEMES[theme] || THEMES.modern;
        const categories = data.categories || [];
        if (categories.length === 0 && !data.problem) return null;

        const numCats = categories.length;
        const numPairs = Math.max(Math.ceil(numCats / 2), 1);

        // SVG Canvas sizing
        const width = Math.max(920, numPairs * 270 + 350);
        const height = 560;
        const spineY = height / 2;

        const spineStartX = 80;
        const spineEndX = width - 230;

        const svgEl = svg.create('svg', {
            width: '100%', height: '100%',
            viewBox: `0 0 ${width} ${height}`,
            class: 'eduvisual-fishbone', style: 'direction:ltr',
            preserveAspectRatio: 'xMidYMid meet'
        });

        const defs = svg.defs();
        defs.appendChild(svg.filter('fbshadow', 3));
        defs.appendChild(svg.filter('headshadow', 4));

        // Arrow marker for main spine
        const spineArrow = svg.create('marker', {
            id: 'fbSpineArrow', viewBox: '0 0 12 8', refX: 11, refY: 4,
            markerWidth: 10, markerHeight: 7, orient: 'auto'
        });
        spineArrow.appendChild(svg.create('polygon', {
            points: '0 0, 12 4, 0 8', fill: t.connectionColor
        }));
        defs.appendChild(spineArrow);

        // Problem header gradient
        const problemColor = '#ef4444';
        defs.appendChild(svg.linearGradient('fbProblemGrad', [problemColor, shadeColor(problemColor, -25)]));

        svgEl.appendChild(defs);
        svgEl.appendChild(svg.roundRect(0, 0, width, height, 16, { fill: t.bg }));

        const mainG = svg.group({ class: 'fishbone-content' });

        // 1. Draw Tail Fin (Left)
        const tailG = svg.group({ class: 'fishbone-tail' });
        tailG.appendChild(svg.create('polygon', {
            points: `${spineStartX - 45},${spineY - 55} ${spineStartX},${spineY} ${spineStartX - 45},${spineY + 55} ${spineStartX - 25},${spineY}`,
            fill: t.connectionColor + '55', stroke: t.connectionColor, 'stroke-width': 2
        }));
        mainG.appendChild(tailG);

        // 2. Draw Main Spine (Horizontal Axis)
        mainG.appendChild(svg.path(`M ${spineStartX} ${spineY} L ${spineEndX} ${spineY}`, {
            stroke: t.connectionColor, 'stroke-width': 4.5, 'stroke-linecap': 'round',
            'marker-end': 'url(#fbSpineArrow)',
            class: animate ? 'animate-draw' : ''
        }));

        // 3. Draw Fish Head (Problem Box on Right)
        const headW = 210, headH = 84;
        const headX = spineEndX + 10, headY = spineY - headH / 2;
        const headG = svg.group({
            class: `fishbone-head interactive ${animate ? 'animate-pop' : ''}`
        });
        headG.appendChild(svg.roundRect(headX, headY, headW, headH, 18, {
            fill: 'url(#fbProblemGrad)', filter: 'url(#headshadow)', stroke: '#fff', 'stroke-width': 2
        }));
        // Head title
        const probText = (data.problem_icon || '🎯') + ' ' + (data.problem || 'المشكلة / الهدف الرئيسي');
        const probWrap = svg.wrapText(headX + headW / 2, spineY, probText, headW - 24, 16, {
            'font-size': 13, 'font-weight': '700', fill: '#ffffff'
        });
        headG.appendChild(probWrap.element);
        mainG.appendChild(headG);

        // 4. Draw Ribs and Sub-Causes
        const ribSpacing = (spineEndX - spineStartX - 100) / Math.max(numPairs, 1);

        categories.forEach((cat, ci) => {
            const pairIdx = Math.floor(ci / 2);
            const isTop = ci % 2 === 0;
            const color = cat.color || t.branchColors[ci % t.branchColors.length];

            // Attach point along spine (slanted backward towards tail)
            const attachX = spineStartX + 90 + pairIdx * ribSpacing + 100;
            const tipX = attachX - 95;
            const tipY = isTop ? 65 : height - 65;

            const catG = svg.group({
                class: `fishbone-category interactive ${animate ? 'animate-pop' : ''}`,
                style: animate ? `animation-delay: ${0.1 + ci * 0.08}s` : '',
                'data-cat-index': ci
            });

            // Slanted Rib Bone Line
            const ribStartY = isTop ? tipY + 22 : tipY - 22;
            mainG.appendChild(svg.path(`M ${tipX} ${ribStartY} L ${attachX} ${spineY}`, {
                stroke: color, 'stroke-width': 3, 'stroke-linecap': 'round', opacity: 0.9,
                class: animate ? 'animate-draw' : ''
            }));

            // Category Capsule / Badge at tip
            const catW = 145, catH = 40;
            catG.appendChild(svg.roundRect(tipX - catW / 2, tipY - catH / 2, catW, catH, 12, {
                fill: color, filter: 'url(#fbshadow)', stroke: '#fff', 'stroke-width': 1.5
            }));
            const catTitle = (cat.icon || '📌') + ' ' + (cat.name || '');
            const catWrap = svg.wrapText(tipX, tipY, catTitle, catW - 16, 14, {
                'font-size': 11.5, 'font-weight': '700', fill: '#ffffff'
            });
            catG.appendChild(catWrap.element);
            mainG.appendChild(catG);

            // Sub-causes (Horizontal branches from the slanted rib)
            const causes = cat.causes || [];
            const numCauses = causes.length;

            causes.forEach((cause, csi) => {
                const causeText = typeof cause === 'string' ? cause : (cause.text || cause.label || '');
                const tParam = (csi + 1) / (numCauses + 1); // 0 < tParam < 1

                // Point on the slanted rib
                const bx = tipX + tParam * (attachX - tipX);
                const by = isTop ? (tipY + tParam * (spineY - tipY)) : (tipY - tParam * (tipY - spineY));

                const causeW = 135, causeH = 32;
                const branchLen = 45;
                const causeCardX = bx - branchLen - causeW / 2;
                const causeCardY = by;

                const causeG = svg.group({
                    class: `fishbone-cause interactive ${animate ? 'animate-pop' : ''}`,
                    style: animate ? `animation-delay: ${0.2 + (ci * 3 + csi) * 0.05}s` : '',
                    'data-cat-index': ci,
                    'data-cause-index': csi
                });

                // Horizontal connector line
                mainG.appendChild(svg.path(`M ${bx} ${by} L ${bx - branchLen} ${by}`, {
                    stroke: color + '99', 'stroke-width': 1.5, 'stroke-dasharray': '3,2'
                }));

                // Cause pill card
                causeG.appendChild(svg.roundRect(causeCardX - causeW / 2, causeCardY - causeH / 2, causeW, causeH, 8, {
                    fill: t.nodeBg, stroke: color + '66', 'stroke-width': 1.5,
                    filter: 'url(#fbshadow)'
                }));

                // Cause Text
                const causeWrap = svg.wrapText(causeCardX, causeCardY, '• ' + causeText, causeW - 14, 13, {
                    'font-size': 10.5, 'font-weight': '600', fill: t.nodeText
                });
                causeG.appendChild(causeWrap.element);

                mainG.appendChild(causeG);
            });
        });

        svgEl.appendChild(mainG);
        container.innerHTML = '';
        container.appendChild(svgEl);
        enableZoomPan(svgEl, mainG);
        enableTooltips(container, svgEl, null);
        return svgEl;
    }

    // ==========================================
    // 11.2 الخريطة الهيكلية (Hierarchy / Tree Renderer)
    // ==========================================
    function renderHierarchySVG(container, data, options) {
        options = options || {};
        const { theme = 'modern', animate = true } = options;
        const t = THEMES[theme] || THEMES.modern;
        const root = data.root;
        if (!root) return null;

        // Calculate tree layout (top-down) — dynamic node width
        const NODE_H = 50, GAP_X = 30, GAP_Y = 80, MIN_W = 100, MAX_W = 200;
        const allNodes = [];
        let maxDepth = 0;

        function calcNodeW(node) {
            const label = (node.icon || '') + ' ' + (node.label || '');
            const measured = TextEngine.measure(label, 11, '600') + 30;
            return Math.min(Math.max(measured, MIN_W), MAX_W);
        }

        function measureTree(node, depth) {
            maxDepth = Math.max(maxDepth, depth);
            node._nodeW = calcNodeW(node);
            const children = node.children || [];
            if (children.length === 0) {
                node._w = node._nodeW;
                return node._nodeW;
            }
            let totalW = 0;
            children.forEach((ch, i) => {
                if (i > 0) totalW += GAP_X;
                totalW += measureTree(ch, depth + 1);
            });
            node._w = Math.max(totalW, node._nodeW);
            return node._w;
        }

        function positionTree(node, x, y, depth) {
            const idx = allNodes.length;
            node._x = x; node._y = y; node._depth = depth; node._idx = idx;
            allNodes.push(node);
            const children = node.children || [];
            if (children.length === 0) return;
            let startX = x - node._w / 2;
            children.forEach((ch) => {
                const cw = ch._w || ch._nodeW || MIN_W;
                positionTree(ch, startX + cw / 2, y + NODE_H + GAP_Y, depth + 1);
                startX += cw + GAP_X;
            });
        }

        const totalW = measureTree(root, 0);
        positionTree(root, totalW / 2, 40, 0);

        const pad = 50;
        const svgW = totalW + pad * 2;
        const svgH = (maxDepth + 1) * (NODE_H + GAP_Y) + pad * 2;

        const svgEl = svg.create('svg', {
            width: '100%', height: '100%',
            viewBox: `${-pad} ${-pad / 2} ${svgW} ${svgH}`,
            class: 'eduvisual-hierarchy', style: 'direction:ltr',
            preserveAspectRatio: 'xMidYMid meet'
        });
        const defs = svg.defs();
        defs.appendChild(svg.filter('hshadow', 3));
        svgEl.appendChild(defs);
        svgEl.appendChild(svg.roundRect(-pad, -pad / 2, svgW, svgH, 16, { fill: t.bg }));

        const mainG = svg.group({ class: 'hierarchy-content' });

        // Draw connections first
        function drawConnections(node) {
            (node.children || []).forEach((ch) => {
                mainG.appendChild(svg.path(
                    `M ${node._x} ${node._y + NODE_H / 2} C ${node._x} ${node._y + NODE_H / 2 + GAP_Y / 2}, ${ch._x} ${ch._y - NODE_H / 2 - GAP_Y / 2}, ${ch._x} ${ch._y - NODE_H / 2}`, {
                    stroke: t.connectionColor, 'stroke-width': 2, 'stroke-linecap': 'round',
                    class: animate ? 'animate-draw' : ''
                }));
                drawConnections(ch);
            });
        }
        drawConnections(root);

        // Draw nodes
        function drawNodes(node) {
            const isRoot = node._depth === 0;
            const color = node.color || t.branchColors[node._depth % t.branchColors.length];
            const nw = node._nodeW;
            const ng = svg.group({
                class: `hierarchy-node interactive ${animate ? 'animate-pop' : ''}`,
                style: animate ? `animation-delay: ${0.05 + node._idx * 0.04}s` : '',
                'data-node-index': node._idx
            });

            if (isRoot) {
                ng.appendChild(svg.roundRect(node._x - nw / 2 - 5, node._y - NODE_H / 2 - 5, nw + 10, NODE_H + 10, 14, {
                    fill: color + '15'
                }));
            }

            ng.appendChild(svg.roundRect(node._x - nw / 2, node._y - NODE_H / 2, nw, NODE_H, 12, {
                fill: isRoot ? color : t.nodeBg, stroke: color, 'stroke-width': isRoot ? 0 : 2,
                filter: 'url(#hshadow)'
            }));

            const icon = node.icon || (isRoot ? '🏢' : '📁');
            const label = icon + ' ' + (node.label || '');
            const lw = svg.wrapText(node._x, node._y - 2, label, nw - 16, 14, {
                'font-size': isRoot ? 12 : 11, 'font-weight': isRoot ? '700' : '600',
                fill: isRoot ? '#fff' : color
            });
            ng.appendChild(lw.element);

            mainG.appendChild(ng);
            (node.children || []).forEach(ch => drawNodes(ch));
        }
        drawNodes(root);

        svgEl.appendChild(mainG);
        container.innerHTML = '';
        container.appendChild(svgEl);
        enableZoomPan(svgEl, mainG);
        enableTooltips(container, svgEl, null);
        return svgEl;
    }

    // ==========================================
    // 11.3 خريطة التدفق (Flowchart Renderer)
    // ==========================================
    function renderFlowchartSVG(container, data, options) {
        options = options || {};
        const { theme = 'modern', animate = true } = options;
        const t = THEMES[theme] || THEMES.modern;
        const steps = data.steps || [];
        if (steps.length === 0) return null;

        const NODE_W = 160, NODE_H = 56, GAP = 70;
        const COLS = Math.min(steps.length, 4);
        const ROWS = Math.ceil(steps.length / COLS);
        const PAD_X = 50, PAD_Y = 50;

        // Zigzag layout: left-to-right, then right-to-left
        const positions = [];
        steps.forEach((step, i) => {
            const row = Math.floor(i / COLS);
            const colInRow = i % COLS;
            const isReverse = row % 2 === 1;
            const col = isReverse ? (COLS - 1 - colInRow) : colInRow;
            positions.push({
                x: PAD_X + NODE_W / 2 + col * (NODE_W + GAP),
                y: PAD_Y + NODE_H / 2 + row * (NODE_H + GAP)
            });
        });

        const svgW = PAD_X * 2 + COLS * NODE_W + Math.max(COLS - 1, 0) * GAP;
        const svgH = PAD_Y * 2 + ROWS * NODE_H + Math.max(ROWS - 1, 0) * GAP;

        const svgEl = svg.create('svg', {
            width: '100%', height: '100%',
            viewBox: `0 0 ${svgW} ${svgH}`,
            class: 'eduvisual-flowchart', style: 'direction:ltr',
            preserveAspectRatio: 'xMidYMid meet'
        });
        const defs = svg.defs();
        defs.appendChild(svg.filter('fshadow', 3));
        const arrowM = svg.create('marker', {
            id: 'flowArrow', viewBox: '0 0 10 7', refX: 10, refY: 3.5,
            markerWidth: 8, markerHeight: 6, orient: 'auto-start-reverse'
        });
        arrowM.appendChild(svg.create('polygon', { points: '0 0, 10 3.5, 0 7', fill: t.connectionColor }));
        defs.appendChild(arrowM);
        svgEl.appendChild(defs);
        svgEl.appendChild(svg.roundRect(0, 0, svgW, svgH, 16, { fill: t.bg }));

        const mainG = svg.group({ class: 'flowchart-content' });

        // Connections (sequential)
        for (let i = 0; i < steps.length - 1; i++) {
            const from = positions[i], to = positions[i + 1];
            const sameRow = Math.floor(i / COLS) === Math.floor((i + 1) / COLS);
            let pathD;
            if (sameRow) {
                const fEdge = { x: from.x + (to.x > from.x ? NODE_W / 2 : -NODE_W / 2), y: from.y };
                const tEdge = { x: to.x + (to.x > from.x ? -NODE_W / 2 : NODE_W / 2), y: to.y };
                pathD = `M ${fEdge.x} ${fEdge.y} L ${tEdge.x} ${tEdge.y}`;
            } else {
                pathD = `M ${from.x} ${from.y + NODE_H / 2} C ${from.x} ${from.y + NODE_H / 2 + 30}, ${to.x} ${to.y - NODE_H / 2 - 30}, ${to.x} ${to.y - NODE_H / 2}`;
            }
            mainG.appendChild(svg.path(pathD, {
                stroke: t.connectionColor, 'stroke-width': 2, 'marker-end': 'url(#flowArrow)',
                class: animate ? 'animate-draw' : '',
                style: animate ? `animation-delay: ${i * 0.06}s` : ''
            }));
        }

        // Nodes
        const typeShapes = { start: 'pill', end: 'pill', decision: 'diamond', process: 'rounded_rect' };
        const typeColors = { start: '#10b981', end: '#ef4444', decision: '#f59e0b', process: '#3b82f6' };

        steps.forEach((step, i) => {
            const pos = positions[i];
            const sType = step.type || 'process';
            const color = typeColors[sType] || '#3b82f6';
            const shape = typeShapes[sType] || 'rounded_rect';

            const ng = svg.group({
                class: `flowchart-node interactive ${animate ? 'animate-pop' : ''}`,
                style: animate ? `animation-delay: ${0.1 + i * 0.06}s` : '',
                'data-step-index': i
            });

            // 1. Shape background
            const shapeBg = ShapeEngine.create(shape, pos.x, pos.y, NODE_W, NODE_H, {
                fill: sType === 'start' || sType === 'end' ? color : t.nodeBg,
                stroke: color, 'stroke-width': 2, filter: 'url(#fshadow)'
            });
            ng.appendChild(shapeBg);

            // 2. Label (clean and centered without numbers since arrows define the flow)
            const icon = step.icon || '';
            const label = (icon ? icon + ' ' : '') + (step.label || '');
            const isFilled = sType === 'start' || sType === 'end';
            const wt = svg.wrapText(pos.x, pos.y, label, NODE_W - 20, 16, {
                'font-size': 11.5, 'font-weight': '600',
                fill: isFilled ? '#fff' : (sType === 'decision' ? color : t.nodeText)
            });
            ng.appendChild(wt.element);

            mainG.appendChild(ng);
        });

        svgEl.appendChild(mainG);
        container.innerHTML = '';
        container.appendChild(svgEl);
        enableZoomPan(svgEl, mainG);
        enableTooltips(container, svgEl, null);
        return svgEl;
    }

    // ==========================================
    // 11.4 خريطة الأسباب والنتائج (Multi-Flow Map Renderer)
    // ==========================================
    function renderMultiFlowSVG(container, data, options) {
        options = options || {};
        const { theme = 'modern', animate = true } = options;
        const t = THEMES[theme] || THEMES.modern;

        const causes = data.causes || [];
        const effects = data.effects || [];
        const event = data.event || {};
        if (causes.length === 0 && effects.length === 0) return null;

        const maxItems = Math.max(causes.length, effects.length, 1);
        const ITEM_H = 54, ITEM_GAP = 14;
        const COL_W = 210; // Width of each cause/effect card
        const CENTER_W = 190, CENTER_H = 72; // Center event card dimensions
        const PAD_X = 40; // Generous margin from outer edges to prevent any clipping
        const ARROW_GAP = 65; // Gap for arrows connecting side cards to center

        const svgW = PAD_X * 2 + COL_W * 2 + CENTER_W + ARROW_GAP * 2;
        const svgH = Math.max(maxItems * (ITEM_H + ITEM_GAP) + 120, 350);

        // Coordinates
        const leftColX = PAD_X; // Left edge of causes column
        const leftColCenterX = leftColX + COL_W / 2; // Center of causes column
        const rightColX = svgW - PAD_X - COL_W; // Left edge of effects column
        const rightColCenterX = rightColX + COL_W / 2; // Center of effects column
        const centerX = svgW / 2;
        const centerY = svgH / 2 + 10; // Shifted slightly down for headers

        // Use theme-aware colors
        const causeColor = t.branchColors[1] || '#3b82f6';
        const effectColor = t.branchColors[0] || '#10b981';
        const eventColor = t.branchColors[3] || '#ef4444';

        const svgEl = svg.create('svg', {
            width: '100%', height: '100%',
            viewBox: `0 0 ${svgW} ${svgH}`,
            class: 'eduvisual-multiflow', style: 'direction:ltr',
            preserveAspectRatio: 'xMidYMid meet'
        });
        const defs = svg.defs();
        defs.appendChild(svg.filter('mfshadow', 3));
        const arrM = svg.create('marker', {
            id: 'mfArrow', viewBox: '0 0 10 7', refX: 10, refY: 3.5,
            markerWidth: 8, markerHeight: 6, orient: 'auto-start-reverse'
        });
        arrM.appendChild(svg.create('polygon', { points: '0 0, 10 3.5, 0 7', fill: t.connectionColor }));
        defs.appendChild(arrM);
        svgEl.appendChild(defs);
        svgEl.appendChild(svg.roundRect(0, 0, svgW, svgH, 16, { fill: t.bg }));

        const mainG = svg.group({ class: 'multiflow-content' });

        // Column labels (aligned exactly above column centers)
        mainG.appendChild(svg.text(leftColCenterX, 32, '🔍 الأسباب', { 'font-size': 14, 'font-weight': '700', fill: causeColor }));
        mainG.appendChild(svg.text(centerX, 32, '⚡ الحدث', { 'font-size': 14, 'font-weight': '700', fill: eventColor }));
        mainG.appendChild(svg.text(rightColCenterX, 32, '💡 النتائج', { 'font-size': 14, 'font-weight': '700', fill: effectColor }));

        // Center event box
        mainG.appendChild(svg.roundRect(centerX - CENTER_W / 2, centerY - CENTER_H / 2, CENTER_W, CENTER_H, CENTER_H / 2, {
            fill: eventColor, filter: 'url(#mfshadow)'
        }));
        const evLabel = (event.icon || '⚡') + ' ' + (event.label || '');
        const evW = svg.wrapText(centerX, centerY, evLabel, CENTER_W - 24, 16, {
            'font-size': 12.5, 'font-weight': '700', fill: '#fff'
        });
        mainG.appendChild(evW.element);

        // Causes (left column)
        const causesStartY = centerY - (causes.length * (ITEM_H + ITEM_GAP) - ITEM_GAP) / 2;
        causes.forEach((c, i) => {
            const cy2 = causesStartY + i * (ITEM_H + ITEM_GAP) + ITEM_H / 2;
            const ng = svg.group({
                class: `multiflow-cause interactive ${animate ? 'animate-pop' : ''}`,
                style: animate ? `animation-delay: ${0.1 + i * 0.06}s` : '',
                'data-cause-index': i
            });
            ng.appendChild(svg.roundRect(leftColX, cy2 - ITEM_H / 2, COL_W, ITEM_H, 10, {
                fill: t.nodeBg, stroke: causeColor, 'stroke-width': 1.5, filter: 'url(#mfshadow)'
            }));
            const cLabel = (c.icon || '🔹') + ' ' + (typeof c === 'string' ? c : c.label || '');
            const clw = svg.wrapText(leftColCenterX, cy2, cLabel, COL_W - 24, 15, {
                'font-size': 11, 'font-weight': '500', fill: t.nodeText
            });
            ng.appendChild(clw.element);

            // Arrow from left box right edge to center box left edge
            const arrowStartX = leftColX + COL_W;
            const arrowEndX = centerX - CENTER_W / 2 - 4;
            mainG.appendChild(svg.path(`M ${arrowStartX} ${cy2} L ${arrowEndX} ${centerY}`, {
                stroke: causeColor + '88', 'stroke-width': 1.5, 'marker-end': 'url(#mfArrow)',
                class: animate ? 'animate-draw' : ''
            }));
            mainG.appendChild(ng);
        });

        // Effects (right column)
        const effectsStartY = centerY - (effects.length * (ITEM_H + ITEM_GAP) - ITEM_GAP) / 2;
        effects.forEach((e, i) => {
            const ey = effectsStartY + i * (ITEM_H + ITEM_GAP) + ITEM_H / 2;
            const ng = svg.group({
                class: `multiflow-effect interactive ${animate ? 'animate-pop' : ''}`,
                style: animate ? `animation-delay: ${0.2 + i * 0.06}s` : '',
                'data-effect-index': i
            });
            ng.appendChild(svg.roundRect(rightColX, ey - ITEM_H / 2, COL_W, ITEM_H, 10, {
                fill: t.nodeBg, stroke: effectColor, 'stroke-width': 1.5, filter: 'url(#mfshadow)'
            }));
            const eLabel = (e.icon || '💎') + ' ' + (typeof e === 'string' ? e : e.label || '');
            const elw = svg.wrapText(rightColCenterX, ey, eLabel, COL_W - 24, 15, {
                'font-size': 11, 'font-weight': '500', fill: t.nodeText
            });
            ng.appendChild(elw.element);

            // Arrow from center box right edge to right box left edge
            const arrowStartX = centerX + CENTER_W / 2 + 4;
            const arrowEndX = rightColX - 4;
            mainG.appendChild(svg.path(`M ${arrowStartX} ${centerY} L ${arrowEndX} ${ey}`, {
                stroke: effectColor + '88', 'stroke-width': 1.5, 'marker-end': 'url(#mfArrow)',
                class: animate ? 'animate-draw' : ''
            }));
            mainG.appendChild(ng);
        });

        svgEl.appendChild(mainG);
        container.innerHTML = '';
        container.appendChild(svgEl);
        enableZoomPan(svgEl, mainG);
        enableTooltips(container, svgEl, null);
        return svgEl;
    }

    // ==========================================
    // 11.5 خريطة الهرم (Pyramid Map Renderer)
    // ==========================================
    function renderPyramidSVG(container, data, options) {
        options = options || {};
        const { theme = 'modern', animate = true } = options;
        const t = THEMES[theme] || THEMES.modern;
        const levels = data.levels || [];
        if (levels.length === 0) return null;

        const n = levels.length;
        const levelH = Math.max(70, 60 + 10);
        const svgW = 800, svgH = Math.max(n * levelH + 100, 400);
        const topX = svgW / 2, topY = 60;
        const baseW = svgW - 120;
        const actualLevelH = (svgH - 100) / n;

        const svgEl = svg.create('svg', {
            width: '100%', height: '100%',
            viewBox: `0 0 ${svgW} ${svgH}`,
            class: 'eduvisual-pyramid', style: 'direction:ltr',
            preserveAspectRatio: 'xMidYMid meet'
        });
        const defs = svg.defs();
        defs.appendChild(svg.filter('pshadow', 3));
        svgEl.appendChild(defs);
        svgEl.appendChild(svg.roundRect(0, 0, svgW, svgH, 16, { fill: t.bg }));

        const mainG = svg.group({ class: 'pyramid-content' });

        levels.forEach((level, i) => {
            const color = level.color || t.branchColors[i % t.branchColors.length];
            const y = topY + i * actualLevelH;
            const progress = i / n;
            const nextProgress = (i + 1) / n;
            const w1 = baseW * progress * 0.9 + 80;
            const w2 = baseW * nextProgress * 0.9 + 80;
            const x1L = topX - w1 / 2, x1R = topX + w1 / 2;
            const x2L = topX - w2 / 2, x2R = topX + w2 / 2;

            const trapD = `M ${x1L} ${y} L ${x1R} ${y} L ${x2R} ${y + actualLevelH - 4} L ${x2L} ${y + actualLevelH - 4} Z`;

            const ng = svg.group({
                class: `pyramid-level interactive ${animate ? 'animate-pop' : ''}`,
                style: animate ? `animation-delay: ${0.1 + i * 0.08}s` : '',
                'data-level-index': i
            });

            ng.appendChild(svg.create('path', {
                d: trapD, fill: color, opacity: 0.88,
                filter: 'url(#pshadow)', stroke: '#fff', 'stroke-width': 2
            }));

            // Label — use wrapText for overflow protection
            const labelY = y + actualLevelH / 2;
            const icon = level.icon || '▸';
            const label = icon + ' ' + (level.label || '');
            const availW = Math.min(w1, w2) + Math.abs(w2 - w1) * 0.5 - 30;
            const lw = svg.wrapText(topX, labelY - (level.description ? 6 : 0), label, Math.max(availW, 100), 16, {
                'font-size': Math.max(13 - i * 0.5, 10), 'font-weight': '700', fill: '#fff'
            });
            ng.appendChild(lw.element);

            // Description — use wrapText
            if (level.description) {
                const dw = svg.wrapText(topX, labelY + 14, level.description, Math.max(availW, 100), 14, {
                    'font-size': 9.5, 'font-weight': '400', fill: '#ffffffcc'
                });
                ng.appendChild(dw.element);
            }

            mainG.appendChild(ng);
        });

        svgEl.appendChild(mainG);
        container.innerHTML = '';
        container.appendChild(svgEl);
        enableZoomPan(svgEl, mainG);
        enableTooltips(container, svgEl, null);
        return svgEl;
    }

    // ==========================================
    // 11.6 الخريطة الدائرية (Circle Map Renderer)
    // ==========================================
    function renderCircleMapSVG(container, data, options) {
        options = options || {};
        const { theme = 'modern', animate = true } = options;
        const t = THEMES[theme] || THEMES.modern;
        const items = data.context_items || [];
        const center = data.center || '';
        if (!center) return null;

        // Dynamic sizing based on item count
        const n = items.length;
        const innerR = 90;
        const outerR = n > 8 ? 320 : (n > 6 ? 300 : 280);
        const svgSize = (outerR + 80) * 2;
        const cx = svgSize / 2, cy = svgSize / 2;

        const svgEl = svg.create('svg', {
            width: '100%', height: '100%',
            viewBox: `0 0 ${svgSize} ${svgSize}`,
            class: 'eduvisual-circlemap', style: 'direction:ltr',
            preserveAspectRatio: 'xMidYMid meet'
        });
        const defs = svg.defs();
        defs.appendChild(svg.filter('csmshadow', 3));
        svgEl.appendChild(defs);
        svgEl.appendChild(svg.roundRect(0, 0, svgSize, svgSize, 16, { fill: t.bg }));

        const mainG = svg.group({ class: 'circlemap-content' });

        // Outer circle
        mainG.appendChild(svg.circle(cx, cy, outerR, {
            fill: 'none', stroke: t.connectionColor + '40', 'stroke-width': 2, 'stroke-dasharray': '8 4'
        }));

        // Middle ring
        const midR = (outerR + innerR) / 2;
        mainG.appendChild(svg.circle(cx, cy, midR, {
            fill: 'none', stroke: t.connectionColor + '20', 'stroke-width': 1
        }));

        // Inner circle (center concept)
        mainG.appendChild(svg.circle(cx, cy, innerR, {
            fill: t.branchColors[0], filter: 'url(#csmshadow)', opacity: 0.9,
            class: animate ? 'animate-pop' : ''
        }));
        const cw = svg.wrapText(cx, cy, data.center_icon ? data.center_icon + '\n' + center : center, innerR * 1.4, 18, {
            'font-size': 14, 'font-weight': '700', fill: '#fff'
        });
        mainG.appendChild(cw.element);

        // Context items — use two rings if many items to avoid overlap
        const useDoubleRing = n > 8;
        const ring1Count = useDoubleRing ? Math.ceil(n / 2) : n;
        const ring2Count = useDoubleRing ? n - ring1Count : 0;
        const ring1R = useDoubleRing ? (midR + innerR) / 2 + 20 : (outerR + midR) / 2;
        const ring2R = useDoubleRing ? (outerR + midR) / 2 + 10 : 0;

        items.forEach((item, i) => {
            let angle, itemR;
            if (useDoubleRing && i >= ring1Count) {
                // Second ring
                const idx = i - ring1Count;
                angle = -Math.PI / 2 + (2 * Math.PI * idx) / ring2Count + Math.PI / ring2Count;
                itemR = ring2R;
            } else {
                // First ring (or single ring)
                angle = -Math.PI / 2 + (2 * Math.PI * i) / ring1Count;
                itemR = ring1R;
            }

            const ix = cx + itemR * Math.cos(angle);
            const iy = cy + itemR * Math.sin(angle);
            const itemText = typeof item === 'string' ? item : (item.text || item.label || '');
            const itemIcon = typeof item === 'object' ? (item.icon || '') : '';
            const color = t.branchColors[i % t.branchColors.length];

            const ng = svg.group({
                class: `circlemap-item interactive ${animate ? 'animate-pop' : ''}`,
                style: animate ? `animation-delay: ${0.15 + i * 0.06}s` : '',
                'data-item-index': i
            });

            const maxBW = useDoubleRing ? 110 : 130;
            const tw = TextEngine.measure(itemText, 10, '600') + 24;
            const bw = Math.min(Math.max(tw, 70), maxBW), bh = 36;

            ng.appendChild(svg.roundRect(ix - bw / 2, iy - bh / 2, bw, bh, bh / 2, {
                fill: t.nodeBg, stroke: color, 'stroke-width': 1.5, filter: 'url(#csmshadow)'
            }));
            const displayText = itemIcon ? itemIcon + ' ' + itemText : itemText;
            const itw = svg.wrapText(ix, iy, displayText, bw - 10, 14, {
                'font-size': 10, 'font-weight': '600', fill: color
            });
            ng.appendChild(itw.element);

            // Connector line to inner circle
            const lineEnd = { x: cx + innerR * Math.cos(angle), y: cy + innerR * Math.sin(angle) };
            const lineStart = { x: ix - (bw / 2) * Math.cos(angle), y: iy - (bh / 2) * Math.sin(angle) };
            mainG.appendChild(svg.create('line', {
                x1: lineStart.x, y1: lineStart.y, x2: lineEnd.x, y2: lineEnd.y,
                stroke: color + '44', 'stroke-width': 1.5, 'stroke-dasharray': '4 3'
            }));

            mainG.appendChild(ng);
        });

        svgEl.appendChild(mainG);
        container.innerHTML = '';
        container.appendChild(svgEl);
        enableZoomPan(svgEl, mainG);
        enableTooltips(container, svgEl, null);
        return svgEl;
    }

    // ==========================================
    // 11b. جدول المقارنة (Comparison Table Renderer)
    // ==========================================
    function renderComparisonTableSVG(container, data, options) {
        options = options || {};
        const { theme = 'modern', animate = true } = options;
        const t = THEMES[theme] || THEMES.modern;
        const criteria = data.criteria || [];
        if (criteria.length === 0) return null;

        const colA = data.column_a || {};
        const colB = data.column_b || {};
        const colorA = colA.color || '#3b82f6';
        const colorB = colB.color || '#10b981';

        // Layout constants
        const padding = 40;
        const headerH = 70;
        const titleH = 60;
        const rowH = 64;
        const colW = 280;
        const labelW = 160;
        const totalW = labelW + colW * 2 + padding * 2;
        const totalH = titleH + headerH + criteria.length * rowH + padding * 2 + 20;

        const svgEl = svg.create('svg', {
            width: '100%', height: '100%',
            viewBox: `0 0 ${totalW} ${totalH}`,
            class: 'eduvisual-comparison', style: 'direction:ltr',
            preserveAspectRatio: 'xMidYMid meet'
        });

        const defs = svg.defs();
        defs.appendChild(svg.filter('cmpshadow', 3));
        defs.appendChild(svg.linearGradient('cmpGradA', [colorA, shadeColor(colorA, -20)]));
        defs.appendChild(svg.linearGradient('cmpGradB', [colorB, shadeColor(colorB, -20)]));
        svgEl.appendChild(defs);

        // Background
        svgEl.appendChild(svg.roundRect(0, 0, totalW, totalH, 16, { fill: t.bg }));

        const mainG = svg.group({ class: 'comparison-content' });
        let y = padding;

        // Title
        if (data.title) {
            const titleEl = svg.text(totalW / 2, y + 20, (data.title || ''), {
                'font-size': 18, 'font-weight': '700', fill: t.textColor,
                'text-anchor': 'middle', 'dominant-baseline': 'middle'
            });
            mainG.appendChild(titleEl);
            y += titleH;
        }

        // Column headers
        const tableX = padding;
        const headerY = y;

        // Criteria column header
        mainG.appendChild(svg.roundRect(tableX, headerY, labelW, headerH, 12, {
            fill: t.connectionColor + '15', stroke: t.connectionColor + '30', 'stroke-width': 1
        }));
        mainG.appendChild(svg.text(tableX + labelW / 2, headerY + headerH / 2, '📋', {
            'font-size': 22, 'text-anchor': 'middle', 'dominant-baseline': 'middle'
        }));

        // Column A header
        const colAX = tableX + labelW + 4;
        mainG.appendChild(svg.roundRect(colAX, headerY, colW, headerH, 12, {
            fill: 'url(#cmpGradA)', filter: 'url(#cmpshadow)',
            class: animate ? 'animate-pop' : ''
        }));
        const iconALabel = (colA.icon || '🔵') + ' ' + (colA.label || 'A');
        mainG.appendChild(svg.text(colAX + colW / 2, headerY + headerH / 2, iconALabel, {
            'font-size': 14, 'font-weight': '700', fill: '#fff',
            'text-anchor': 'middle', 'dominant-baseline': 'middle'
        }));

        // Column B header
        const colBX = colAX + colW + 4;
        mainG.appendChild(svg.roundRect(colBX, headerY, colW, headerH, 12, {
            fill: 'url(#cmpGradB)', filter: 'url(#cmpshadow)',
            class: animate ? 'animate-pop' : '',
            style: animate ? 'animation-delay: 0.1s' : ''
        }));
        const iconBLabel = (colB.icon || '🟢') + ' ' + (colB.label || 'B');
        mainG.appendChild(svg.text(colBX + colW / 2, headerY + headerH / 2, iconBLabel, {
            'font-size': 14, 'font-weight': '700', fill: '#fff',
            'text-anchor': 'middle', 'dominant-baseline': 'middle'
        }));

        y = headerY + headerH + 6;

        // Rows
        criteria.forEach((crit, i) => {
            const rowY = y + i * rowH;
            const isEven = i % 2 === 0;
            const rowBg = isEven ? (t.bg === '#ffffff' ? '#f8fafc' : t.nodeBg) : t.bg;
            const delay = animate ? `animation-delay: ${0.15 + i * 0.06}s` : '';

            // Criterion label cell
            mainG.appendChild(svg.roundRect(tableX, rowY, labelW, rowH - 4, 8, {
                fill: rowBg, stroke: t.connectionColor + '20', 'stroke-width': 1,
                class: animate ? 'animate-pop' : '', style: delay
            }));
            const critLabel = (crit.icon || '📌') + ' ' + (crit.label || '');
            const critWrap = svg.wrapText(tableX + labelW / 2, rowY + rowH / 2 - 2, critLabel, labelW - 16, 14, {
                'font-size': 11, 'font-weight': '700', fill: t.textColor
            });
            mainG.appendChild(critWrap.element);

            // Value A cell
            mainG.appendChild(svg.roundRect(colAX, rowY, colW, rowH - 4, 8, {
                fill: rowBg, stroke: colorA + '30', 'stroke-width': 1.5,
                class: animate ? 'animate-pop' : '', style: delay
            }));
            // Color accent bar
            mainG.appendChild(svg.create('rect', {
                x: colAX, y: rowY + 4, width: 4, height: rowH - 12, rx: 2, fill: colorA
            }));
            const valAWrap = svg.wrapText(colAX + colW / 2 + 2, rowY + rowH / 2 - 2, crit.value_a || '', colW - 24, 14, {
                'font-size': 11, 'font-weight': '500', fill: t.textColor
            });
            mainG.appendChild(valAWrap.element);

            // Value B cell
            mainG.appendChild(svg.roundRect(colBX, rowY, colW, rowH - 4, 8, {
                fill: rowBg, stroke: colorB + '30', 'stroke-width': 1.5,
                class: animate ? 'animate-pop' : '', style: delay
            }));
            // Color accent bar
            mainG.appendChild(svg.create('rect', {
                x: colBX, y: rowY + 4, width: 4, height: rowH - 12, rx: 2, fill: colorB
            }));
            const valBWrap = svg.wrapText(colBX + colW / 2 + 2, rowY + rowH / 2 - 2, crit.value_b || '', colW - 24, 14, {
                'font-size': 11, 'font-weight': '500', fill: t.textColor
            });
            mainG.appendChild(valBWrap.element);
        });

        svgEl.appendChild(mainG);
        container.innerHTML = '';
        container.appendChild(svgEl);
        enableZoomPan(svgEl, mainG);
        enableTooltips(container, svgEl, null);
        return svgEl;
    }

    // ==========================================
    // 11b. رسم خريطة دورية (Cycle Map)
    // ==========================================
    function renderCycleMapSVG(container, data, options) {
        options = options || {};
        const { theme = 'modern', animate = true } = options;
        const t = THEMES[theme] || THEMES.modern;
        const steps = data.steps || [];
        if (steps.length === 0) return null;

        const n = steps.length;
        const centerX = 400, centerY = 320;
        const radius = Math.min(240, 180 + n * 8);
        const nodeW = 140, nodeH = 62;
        const W = 800, H = 660;

        const svgEl = svg.create('svg', {
            width: '100%',
            height: '100%',
            viewBox: `0 0 ${W} ${H}`,
            class: 'eduvisual-cyclemap',
            style: 'direction: ltr',
            preserveAspectRatio: 'xMidYMid meet'
        });
        const mainG = svg.create('g', { class: 'cycle-map-root' });

        // Background
        mainG.appendChild(svg.create('rect', {
            x: 0, y: 0, width: W, height: H, rx: 20, fill: t.bg
        }));

        // Title
        const titleText = (data.center_icon || '🔄') + ' ' + (data.title || 'الخريطة الدورية');
        mainG.appendChild(svg.create('text', {
            x: centerX, y: 36, 'text-anchor': 'middle', 'font-size': 18, 'font-weight': '800', fill: t.nodeText
        }));
        mainG.lastChild.textContent = titleText;

        // Center circle
        const centerColor = t.branchColors[0] || '#667eea';
        const centerLabel = data.center_label || data.title || '';
        mainG.appendChild(svg.create('circle', {
            cx: centerX, cy: centerY, r: 50,
            fill: centerColor, opacity: 0.15
        }));
        mainG.appendChild(svg.create('circle', {
            cx: centerX, cy: centerY, r: 50,
            fill: 'none', stroke: centerColor, 'stroke-width': 2.5, 'stroke-dasharray': '6 3'
        }));
        const centerWrap = svg.wrapText(centerX, centerY, centerLabel, 80, 14, {
            'font-size': 12, 'font-weight': '700', fill: t.nodeText
        });
        mainG.appendChild(centerWrap.element);

        // Default palette
        const defaultColors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4', '#f97316'];

        // Draw arrow arcs between steps
        const angleStep = (2 * Math.PI) / n;
        const startAngle = -Math.PI / 2;

        for (let i = 0; i < n; i++) {
            const a1 = startAngle + i * angleStep;
            const a2 = startAngle + ((i + 1) % n) * angleStep;
            const mid = (a1 + a2) / 2;
            const arcR = radius + 15;
            const x1 = centerX + (radius - 10) * Math.cos(a1 + 0.15);
            const y1 = centerY + (radius - 10) * Math.sin(a1 + 0.15);
            const x2 = centerX + (radius - 10) * Math.cos(a2 - 0.15);
            const y2 = centerY + (radius - 10) * Math.sin(a2 - 0.15);
            const mx = centerX + arcR * Math.cos(mid);
            const my = centerY + arcR * Math.sin(mid);
            const color = steps[i].color || defaultColors[i % defaultColors.length];

            // Curved arrow
            const path = svg.create('path', {
                d: `M ${x1} ${y1} Q ${mx} ${my} ${x2} ${y2}`,
                fill: 'none', stroke: color, 'stroke-width': 2.5, opacity: 0.5,
                'marker-end': `url(#arrow-${i})`,
                class: animate ? 'animate-pop' : '',
                style: `animation-delay: ${i * 0.08}s`
            });
            mainG.appendChild(path);

            // Arrow marker
            const defs = svg.create('defs', {});
            const marker = svg.create('marker', {
                id: `arrow-${i}`, markerWidth: 8, markerHeight: 8, refX: 6, refY: 4, orient: 'auto', fill: color
            });
            marker.appendChild(svg.create('path', { d: 'M0,1 L6,4 L0,7 Z', fill: color }));
            defs.appendChild(marker);
            mainG.appendChild(defs);
        }

        // Draw step nodes
        steps.forEach((step, i) => {
            const angle = startAngle + i * angleStep;
            const nx = centerX + radius * Math.cos(angle);
            const ny = centerY + radius * Math.sin(angle);
            const color = step.color || defaultColors[i % defaultColors.length];
            const delay = `animation-delay: ${i * 0.1}s`;

            // Node background
            mainG.appendChild(svg.roundRect(nx - nodeW / 2, ny - nodeH / 2, nodeW, nodeH, 14, {
                fill: t.nodeBg, stroke: color, 'stroke-width': 2.5,
                filter: 'drop-shadow(0 2px 6px rgba(0,0,0,0.12))',
                class: animate ? 'animate-pop' : '', style: delay
            }));

            // Step number badge
            mainG.appendChild(svg.create('circle', {
                cx: nx - nodeW / 2 + 14, cy: ny - nodeH / 2 + 14, r: 12,
                fill: color
            }));
            mainG.appendChild(svg.create('text', {
                x: nx - nodeW / 2 + 14, y: ny - nodeH / 2 + 18,
                'text-anchor': 'middle', 'font-size': 11, 'font-weight': '800', fill: '#fff'
            }));
            mainG.lastChild.textContent = String(i + 1);

            // Icon / emoji
            const icon = step.icon || '📌';
            mainG.appendChild(svg.create('text', {
                x: nx + nodeW / 2 - 16, y: ny - nodeH / 2 + 18,
                'text-anchor': 'middle', 'font-size': 13
            }));
            mainG.lastChild.textContent = icon;

            // Label
            const labelWrap = svg.wrapText(nx, ny + 2, step.label || '', nodeW - 20, 14, {
                'font-size': 11, 'font-weight': '700', fill: t.nodeText
            });
            mainG.appendChild(labelWrap.element);

            // Tooltip with description
            if (step.description) {
                const hitArea = svg.roundRect(nx - nodeW / 2, ny - nodeH / 2, nodeW, nodeH, 14, {
                    fill: 'transparent', stroke: 'none', class: 'ev-tooltip-trigger',
                    'data-tooltip': step.description
                });
                mainG.appendChild(hitArea);
            }
        });

        svgEl.appendChild(mainG);
        container.innerHTML = '';
        container.appendChild(svgEl);
        enableZoomPan(svgEl, mainG);
        enableTooltips(container, svgEl, null);
        return svgEl;
    }

    // ==========================================
    // 12. التكبير والتصغير والسحب (Zoom/Pan)
    // ==========================================
    function enableZoomPan(svgEl, contentGroup) {
        let viewBox = svgEl.viewBox.baseVal;
        let isPanning = false;
        let startPoint = { x: 0, y: 0 };
        let scale = 1;
        const minScale = 0.3;
        const maxScale = 3;

        // Store original viewBox for full-chart export
        svgEl.setAttribute('data-original-viewbox', viewBox.x + ' ' + viewBox.y + ' ' + viewBox.width + ' ' + viewBox.height);

        // Wheel zoom
        svgEl.addEventListener('wheel', (e) => {
            e.preventDefault();
            const delta = e.deltaY > 0 ? 0.9 : 1.1;
            const newScale = Math.max(minScale, Math.min(maxScale, scale * delta));

            const pt = svgEl.createSVGPoint();
            pt.x = e.clientX;
            pt.y = e.clientY;
            const svgPt = pt.matrixTransform(svgEl.getScreenCTM().inverse());

            const ratio = 1 - newScale / scale;
            viewBox.x += (svgPt.x - viewBox.x) * ratio;
            viewBox.y += (svgPt.y - viewBox.y) * ratio;
            viewBox.width *= scale / newScale;
            viewBox.height *= scale / newScale;

            scale = newScale;
        }, { passive: false });

        // Mouse pan — exclude all interactive element types from pan
        svgEl.addEventListener('mousedown', (e) => {
            if (e.target.closest('.node, .concept-node, .fishbone-category, .hierarchy-node, .flowchart-node, .multiflow-cause, .multiflow-effect, .pyramid-level, .circlemap-item, .summary-card, .interactive')) return;
            isPanning = true;
            startPoint = { x: e.clientX, y: e.clientY };
            svgEl.style.cursor = 'grabbing';
        });

        svgEl.addEventListener('mousemove', (e) => {
            if (!isPanning) return;
            const dx = (e.clientX - startPoint.x) * (viewBox.width / svgEl.clientWidth);
            const dy = (e.clientY - startPoint.y) * (viewBox.height / svgEl.clientHeight);
            viewBox.x -= dx;
            viewBox.y -= dy;
            startPoint = { x: e.clientX, y: e.clientY };
        });

        svgEl.addEventListener('mouseup', () => {
            isPanning = false;
            svgEl.style.cursor = 'grab';
        });

        svgEl.addEventListener('mouseleave', () => {
            isPanning = false;
            svgEl.style.cursor = 'grab';
        });

        svgEl.style.cursor = 'grab';

        // Touch support
        let lastTouchDist = 0;
        svgEl.addEventListener('touchstart', (e) => {
            if (e.touches.length === 1) {
                isPanning = true;
                startPoint = { x: e.touches[0].clientX, y: e.touches[0].clientY };
            } else if (e.touches.length === 2) {
                lastTouchDist = Math.hypot(
                    e.touches[0].clientX - e.touches[1].clientX,
                    e.touches[0].clientY - e.touches[1].clientY
                );
            }
        }, { passive: true });

        svgEl.addEventListener('touchmove', (e) => {
            if (e.touches.length === 1 && isPanning) {
                e.preventDefault();
                const dx = (e.touches[0].clientX - startPoint.x) * (viewBox.width / svgEl.clientWidth);
                const dy = (e.touches[0].clientY - startPoint.y) * (viewBox.height / svgEl.clientHeight);
                viewBox.x -= dx;
                viewBox.y -= dy;
                startPoint = { x: e.touches[0].clientX, y: e.touches[0].clientY };
            } else if (e.touches.length === 2) {
                e.preventDefault();
                const dist = Math.hypot(
                    e.touches[0].clientX - e.touches[1].clientX,
                    e.touches[0].clientY - e.touches[1].clientY
                );
                if (lastTouchDist > 0) {
                    const pinchScale = dist / lastTouchDist;
                    const newScale = Math.max(minScale, Math.min(maxScale, scale * pinchScale));
                    viewBox.width *= scale / newScale;
                    viewBox.height *= scale / newScale;
                    scale = newScale;
                }
                lastTouchDist = dist;
            }
        }, { passive: false });

        svgEl.addEventListener('touchend', () => {
            isPanning = false;
            lastTouchDist = 0;
        });

        // Double-click to reset view (skip if target is an interactive node)
        svgEl.addEventListener('dblclick', (e) => {
            if (e.target.closest('.node, .concept-node, .fishbone-category, .hierarchy-node, .flowchart-node, .multiflow-cause, .multiflow-effect, .pyramid-level, .circlemap-item, .venn-item-a, .venn-item-b, .venn-item-shared, .timeline-event, .summary-card, .interactive')) return;
            const parts = svgEl.getAttribute('viewBox').split(/\s+/);
            viewBox.x = parseFloat(parts[0]);
            viewBox.y = parseFloat(parts[1]);
            viewBox.width = parseFloat(parts[2]);
            viewBox.height = parseFloat(parts[3]);
            scale = 1;
        });
    }

    // ==========================================
    // التلميحات (Tooltips)
    // ==========================================
    function enableTooltips(container, svgEl, nodes) {
        const tooltip = document.createElement('div');
        tooltip.className = 'eduvisual-tooltip';
        tooltip.style.display = 'none';
        container.style.position = 'relative';
        container.appendChild(tooltip);

        // Support tooltips for all interactive node types
        const interactiveSelector = '.node-sub.interactive, .concept-node, .fishbone-category.interactive, .hierarchy-node.interactive, .flowchart-node.interactive, .multiflow-cause.interactive, .multiflow-effect.interactive, .pyramid-level.interactive, .circlemap-item.interactive, .venn-item-a.interactive, .venn-item-b.interactive, .venn-item-shared.interactive, .timeline-event.interactive';

        svgEl.querySelectorAll(interactiveSelector).forEach(nodeEl => {
            // Keyboard accessibility — make SVG nodes focusable
            nodeEl.setAttribute('tabindex', '0');
            nodeEl.setAttribute('role', 'button');

            // Try to find matching data from multiple index attributes
            let label = '', details = '', icon = '';

            // Mind map sub-nodes (original behavior)
            const idx = nodeEl.getAttribute('data-index');
            if (idx !== null && nodes && nodes[parseInt(idx)]) {
                const node = nodes[parseInt(idx)];
                if (!node.details) return;
                label = node.text || '';
                details = node.details || '';
                icon = node.icon || '';
            } else {
                // Get tooltip info from SVG text content for other node types
                const texts = nodeEl.querySelectorAll('text');
                if (texts.length > 0) label = texts[0].textContent || '';
                if (texts.length > 1) details = texts[1].textContent || '';
                // Skip tooltip if no meaningful content
                if (!label && !details) return;
            }

            nodeEl.addEventListener('mouseenter', (e) => {
                tooltip.innerHTML = `
                    ${icon ? `<div class="ev-tooltip-icon">${escapeHtml(icon)}</div>` : ''}
                    <div class="ev-tooltip-title">${escapeHtml(label)}</div>
                    ${details ? `<div class="ev-tooltip-details">${escapeHtml(details)}</div>` : ''}
                `;
                tooltip.style.display = 'block';
                positionTooltip(e, tooltip, container);
            });

            nodeEl.addEventListener('mousemove', (e) => {
                positionTooltip(e, tooltip, container);
            });

            nodeEl.addEventListener('mouseleave', () => {
                tooltip.style.display = 'none';
            });
        });
    }

    function positionTooltip(e, tooltip, container) {
        const rect = container.getBoundingClientRect();
        let x = e.clientX - rect.left + 15;
        let y = e.clientY - rect.top - 10;

        if (x + 250 > rect.width) x = x - 280;
        if (y + 100 > rect.height) y = y - 80;

        tooltip.style.left = x + 'px';
        tooltip.style.top = y + 'px';
    }

    // ==========================================
    // 12.5a نظام التراجع والإعادة (Undo/Redo History)
    // ==========================================
    const UndoManager = (() => {
        const MAX_HISTORY = 30;
        let history = [];
        let pointer = -1;
        let _data = null;
        let _rerenderFn = null;

        function snapshot() {
            if (!_data) return;
            // Remove future states
            if (pointer < history.length - 1) history = history.slice(0, pointer + 1);
            history.push(JSON.stringify(_data));
            if (history.length > MAX_HISTORY) history.shift();
            pointer = history.length - 1;
        }

        function undo() {
            if (pointer <= 0 || !_data) return false;
            pointer--;
            const state = JSON.parse(history[pointer]);
            Object.keys(_data).forEach(k => delete _data[k]);
            Object.assign(_data, state);
            if (_rerenderFn) _rerenderFn();
            return true;
        }

        function redo() {
            if (pointer >= history.length - 1 || !_data) return false;
            pointer++;
            const state = JSON.parse(history[pointer]);
            Object.keys(_data).forEach(k => delete _data[k]);
            Object.assign(_data, state);
            if (_rerenderFn) _rerenderFn();
            return true;
        }

        function init(data, rerenderFn) {
            _data = data;
            _rerenderFn = rerenderFn;
            history = [JSON.stringify(data)];
            pointer = 0;
        }

        function canUndo() { return pointer > 0; }
        function canRedo() { return pointer < history.length - 1; }
        function clear() { history = []; pointer = -1; _data = null; _rerenderFn = null; }

        return { init, snapshot, undo, redo, canUndo, canRedo, clear };
    })();

    // ==========================================
    // 12.5b Long Press Detection (Mobile)
    // ==========================================
    function addLongPress(el, callback, duration) {
        duration = duration || 500;
        let timer = null;
        let moved = false;

        el.addEventListener('touchstart', (e) => {
            moved = false;
            timer = setTimeout(() => {
                if (!moved) callback(e);
            }, duration);
        }, { passive: true });

        el.addEventListener('touchmove', () => { moved = true; clearTimeout(timer); }, { passive: true });
        el.addEventListener('touchend', () => clearTimeout(timer), { passive: true });
        el.addEventListener('touchcancel', () => clearTimeout(timer), { passive: true });
    }

    // ==========================================
    // 12.5 نظام التحرير التفاعلي (Interactive Editing System)
    // ==========================================

    /** Available colors for the color picker */
    const EDIT_COLORS = [
        '#10b981', '#3b82f6', '#f59e0b', '#ef4444',
        '#8b5cf6', '#ec4899', '#06b6d4', '#f97316',
        '#667eea', '#14b8a6', '#e11d48', '#7c3aed'
    ];

    /** Available emojis for quick selection */
    const EDIT_EMOJIS = [
        '🎯', '📚', '📝', '💡', '🔬', '🧪', '📊', '🎨',
        '⭐', '🔑', '🏆', '📌', '✅', '❓', '🔗', '📋',
        '🧠', '💻', '🌍', '⚡', '🔧', '📐', '🎓', '📖'
    ];

    /**
     * Enable editing on a mind map
     * @param {HTMLElement} container - wrapper element
     * @param {object} data - original mind map data reference (will be mutated)
     * @param {function} rerenderFn - function to re-render the map
     */
    function enableEditing(container, data, rerenderFn) {
        // Remove existing edit panel if any
        const existingPanel = container.querySelector('.ev-edit-panel');
        if (existingPanel) existingPanel.remove();

        const svgEl = container.querySelector('svg');
        if (!svgEl) return;

        // Double-click on nodes to edit
        svgEl.addEventListener('dblclick', (e) => {
            const nodeEl = e.target.closest('.node');
            if (!nodeEl) return;

            e.stopPropagation();
            e.preventDefault();

            const idx = parseInt(nodeEl.getAttribute('data-index'));
            const nodeType = nodeEl.classList.contains('node-center') ? 'center' :
                nodeEl.classList.contains('node-branch') ? 'branch' : 'sub';

            // Find the original data for this node by reparse
            const layoutNodes = getLayoutNodesFromData(data);
            const layoutNode = layoutNodes[idx];
            if (!layoutNode) return;

            // Get original data reference
            let editTarget = null;
            let editType = nodeType;

            if (nodeType === 'center') {
                editTarget = {
                    text: data.central_node || '',
                    icon: data.central_icon || '🎯',
                    color: data.central_color || '#667eea',
                    shape: 'pill'
                };
            } else if (nodeType === 'branch' && layoutNode.branchIdx !== undefined) {
                const branch = data.branches[layoutNode.branchIdx];
                if (branch) {
                    editTarget = {
                        text: branch.title || '',
                        icon: branch.icon || '📌',
                        color: branch.color || '#3b82f6',
                        shape: branch.shape || 'rounded_rect'
                    };
                }
            } else if (nodeType === 'sub' && layoutNode.branchIdx !== undefined && layoutNode.subIdx !== undefined) {
                const sub = data.branches[layoutNode.branchIdx]?.sub_branches?.[layoutNode.subIdx];
                if (sub) {
                    editTarget = {
                        text: sub.title || '',
                        icon: sub.icon || '📝',
                        color: data.branches[layoutNode.branchIdx].color || '#3b82f6',
                        details: sub.details || '',
                        shape: sub.shape || 'rounded_rect'
                    };
                }
            }

            if (editTarget) {
                // Highlight selected node
                svgEl.querySelectorAll('.node-selected').forEach(n => n.classList.remove('node-selected'));
                nodeEl.classList.add('node-selected');

                showEditPanel(container, editTarget, editType, (updatedData) => {
                    // Apply changes back to data
                    if (nodeType === 'center') {
                        data.central_node = updatedData.text;
                        data.central_icon = updatedData.icon;
                        data.central_color = updatedData.color;
                    } else if (nodeType === 'branch') {
                        const branch = data.branches[layoutNode.branchIdx];
                        if (branch) {
                            branch.title = updatedData.text;
                            branch.icon = updatedData.icon;
                            branch.color = updatedData.color;
                            branch.shape = updatedData.shape;
                        }
                    } else if (nodeType === 'sub') {
                        const sub = data.branches[layoutNode.branchIdx]?.sub_branches?.[layoutNode.subIdx];
                        if (sub) {
                            sub.title = updatedData.text;
                            sub.icon = updatedData.icon;
                            if (updatedData.details !== undefined) sub.details = updatedData.details;
                            sub.shape = updatedData.shape;
                        }
                    }
                    // Re-render
                    rerenderFn();
                }, () => {
                    // On cancel - remove highlight
                    nodeEl.classList.remove('node-selected');
                }, layoutNode, data, rerenderFn);
            }
        });

        // Long-press on mobile triggers same edit flow
        addLongPress(svgEl, (e) => {
            const touch = e.touches && e.touches[0];
            const target = touch ? document.elementFromPoint(touch.clientX, touch.clientY) : e.target;
            if (target) {
                const node = target.closest ? target.closest('.node') : null;
                if (node) node.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }));
            }
        });

        // Add edit hint to toolbar
        const toolbar = container.querySelector('.eduvisual-toolbar');
        if (toolbar && !toolbar.querySelector('.ev-edit-hint')) {
            const hint = document.createElement('span');
            hint.className = 'ev-toolbar-hint ev-edit-hint';
            hint.innerHTML = '<i class="fas fa-edit"></i> انقر مرتين على أي عنصر للتعديل';
            const firstGroup = toolbar.querySelector('.ev-toolbar-group');
            if (firstGroup) firstGroup.appendChild(hint);
        }
    }

    /**
     * Get flat layout nodes from data (mirrors calculateMindMapLayout node order)
     */
    function getLayoutNodesFromData(data) {
        const nodes = [];
        // center
        nodes.push({ type: 'center', branchIdx: -1, subIdx: -1 });
        // branches and their subs
        (data.branches || []).forEach((branch, i) => {
            nodes.push({ type: 'branch', branchIdx: i, subIdx: -1 });
            (branch.sub_branches || []).forEach((sub, j) => {
                nodes.push({ type: 'sub', branchIdx: i, subIdx: j });
            });
        });
        return nodes;
    }

    /**
     * Show the edit panel for a node
     */
    function showEditPanel(container, editData, editType, onSave, onCancel, layoutNode, fullData, rerenderFn) {
        // Remove existing panel
        closeEditPanel(container);

        const panel = document.createElement('div');
        panel.className = 'ev-edit-panel';
        panel.innerHTML = `
            <div class="ev-edit-header">
                <h5><i class="fas fa-edit"></i> تعديل العنصر</h5>
                <button class="ev-edit-close" title="إغلاق">&times;</button>
            </div>
            <div class="ev-edit-body">
                <div class="ev-edit-field">
                    <label><i class="fas fa-font"></i> النص</label>
                    <textarea class="ev-edit-text" rows="2" dir="rtl">${escapeHtml(editData.text)}</textarea>
                </div>
                ${editType === 'sub' ? `
                <div class="ev-edit-field">
                    <label><i class="fas fa-info-circle"></i> التفاصيل</label>
                    <textarea class="ev-edit-details" rows="2" dir="rtl">${escapeHtml(editData.details || '')}</textarea>
                </div>` : ''}
                <div class="ev-edit-field">
                    <label><i class="fas fa-smile"></i> الأيقونة</label>
                    <div class="ev-edit-icon-row">
                        <input type="text" class="ev-edit-icon-input" value="${escapeHtml(editData.icon)}" maxlength="4">
                        <div class="ev-edit-emoji-grid">
                            ${EDIT_EMOJIS.map(e => `<button class="ev-emoji-btn${e === editData.icon ? ' active' : ''}" data-emoji="${e}">${e}</button>`).join('')}
                        </div>
                    </div>
                </div>
                <div class="ev-edit-field">
                    <label><i class="fas fa-palette"></i> اللون</label>
                    <div class="ev-edit-color-grid">
                        ${EDIT_COLORS.map(c => `<button class="ev-color-btn${c === editData.color ? ' active' : ''}" data-color="${c}" style="background:${c}"></button>`).join('')}
                    </div>
                </div>
                ${editType !== 'center' ? `
                <div class="ev-edit-field">
                    <label><i class="fas fa-shapes"></i> الشكل</label>
                    <div class="ev-edit-shape-grid">
                        ${Object.entries(SHAPES).map(([key, s]) => `<button class="ev-shape-btn${key === editData.shape ? ' active' : ''}" data-shape="${key}" title="${s.name}">${s.icon}</button>`).join('')}
                    </div>
                </div>` : ''}
                <div class="ev-edit-actions-section">
                    ${editType === 'center' ? `<button class="ev-action-btn ev-add-branch-btn"><i class="fas fa-plus-circle"></i> إضافة فرع جديد</button>` : ''}
                    ${editType === 'branch' ? `<button class="ev-action-btn ev-add-sub-btn"><i class="fas fa-plus-circle"></i> إضافة فرع فرعي</button>` : ''}
                    ${editType !== 'center' ? `<button class="ev-action-btn ev-delete-btn"><i class="fas fa-trash-alt"></i> حذف العنصر</button>` : ''}
                </div>
            </div>
            <div class="ev-edit-footer">
                <button class="ev-edit-save"><i class="fas fa-check"></i> حفظ</button>
                <button class="ev-edit-cancel"><i class="fas fa-times"></i> إلغاء</button>
            </div>
        `;

        container.style.position = 'relative';
        container.appendChild(panel);

        // Animate in
        requestAnimationFrame(() => panel.classList.add('visible'));

        // --- Event handlers ---
        const textInput = panel.querySelector('.ev-edit-text');
        const detailsInput = panel.querySelector('.ev-edit-details');
        const iconInput = panel.querySelector('.ev-edit-icon-input');

        // Emoji buttons
        panel.querySelectorAll('.ev-emoji-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                panel.querySelectorAll('.ev-emoji-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                iconInput.value = btn.dataset.emoji;
            });
        });

        // Color buttons
        panel.querySelectorAll('.ev-color-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                panel.querySelectorAll('.ev-color-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
            });
        });

        // Shape buttons
        panel.querySelectorAll('.ev-shape-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                panel.querySelectorAll('.ev-shape-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
            });
        });

        // Add branch button
        const addBranchBtn = panel.querySelector('.ev-add-branch-btn');
        if (addBranchBtn) {
            addBranchBtn.addEventListener('click', () => {
                const newBranch = {
                    id: (fullData.branches || []).length + 1,
                    title: 'فرع جديد',
                    icon: '📌',
                    color: EDIT_COLORS[((fullData.branches || []).length) % EDIT_COLORS.length],
                    shape: 'rounded_rect',
                    sub_branches: []
                };
                if (!fullData.branches) fullData.branches = [];
                UndoManager.snapshot();
                fullData.branches.push(newBranch);
                closeEditPanel(container);
                rerenderFn();
            });
        }

        // Add sub-branch button
        const addSubBtn = panel.querySelector('.ev-add-sub-btn');
        if (addSubBtn && layoutNode && layoutNode.branchIdx !== undefined) {
            addSubBtn.addEventListener('click', () => {
                const branch = fullData.branches[layoutNode.branchIdx];
                if (branch) {
                    if (!branch.sub_branches) branch.sub_branches = [];
                    UndoManager.snapshot();
                    branch.sub_branches.push({
                        id: branch.sub_branches.length + 1,
                        title: 'فرع فرعي جديد',
                        icon: '📝',
                        details: '',
                        shape: 'rounded_rect'
                    });
                    closeEditPanel(container);
                    rerenderFn();
                }
            });
        }

        // Delete button
        const deleteBtn = panel.querySelector('.ev-delete-btn');
        if (deleteBtn && layoutNode) {
            deleteBtn.addEventListener('click', () => {
                UndoManager.snapshot();
                if (editType === 'branch' && layoutNode.branchIdx !== undefined) {
                    fullData.branches.splice(layoutNode.branchIdx, 1);
                } else if (editType === 'sub' && layoutNode.branchIdx !== undefined && layoutNode.subIdx !== undefined) {
                    const branch = fullData.branches[layoutNode.branchIdx];
                    if (branch && branch.sub_branches) {
                        branch.sub_branches.splice(layoutNode.subIdx, 1);
                    }
                }
                closeEditPanel(container);
                rerenderFn();
            });
        }

        // Save
        panel.querySelector('.ev-edit-save').addEventListener('click', () => {
            UndoManager.snapshot();
            const result = {
                text: textInput.value.trim() || editData.text,
                icon: iconInput.value || editData.icon,
                color: panel.querySelector('.ev-color-btn.active')?.dataset.color || editData.color,
                shape: panel.querySelector('.ev-shape-btn.active')?.dataset.shape || editData.shape
            };
            if (detailsInput) result.details = detailsInput.value.trim();
            closeEditPanel(container);
            onSave(result);
        });

        // Cancel
        panel.querySelector('.ev-edit-cancel').addEventListener('click', () => {
            closeEditPanel(container);
            onCancel();
        });

        // Close button
        panel.querySelector('.ev-edit-close').addEventListener('click', () => {
            closeEditPanel(container);
            onCancel();
        });

        // Focus text input
        setTimeout(() => textInput.focus(), 150);
    }

    /**
     * Close the edit panel
     */
    function closeEditPanel(container) {
        const panel = container.querySelector('.ev-edit-panel');
        if (panel) {
            panel.classList.remove('visible');
            setTimeout(() => panel.remove(), 200);
        }
        // Remove selection highlights
        const svgEl = container.querySelector('svg');
        if (svgEl) svgEl.querySelectorAll('.node-selected').forEach(n => n.classList.remove('node-selected'));
    }

    // ==========================================
    // 12.6 تحرير الخرائط المفاهيمية (Concept Map Editing)
    // ==========================================
    function enableConceptEditing(container, data, rerenderFn) {
        const existingPanel = container.querySelector('.ev-edit-panel');
        if (existingPanel) existingPanel.remove();

        const svgEl = container.querySelector('svg');
        if (!svgEl) return;

        svgEl.addEventListener('dblclick', (e) => {
            const nodeEl = e.target.closest('.concept-node');
            if (!nodeEl) return;

            e.stopPropagation();
            e.preventDefault();

            const ni = parseInt(nodeEl.getAttribute('data-node-index'));
            const nodesData = data.nodes || [];
            if (ni < 0 || ni >= nodesData.length) return;

            const nd = nodesData[ni];

            const editData = {
                text: nd.label || '',
                icon: nd.icon || '💡',
                color: '#3b82f6',
                shape: nd.shape || 'rounded_rect'
            };

            svgEl.querySelectorAll('.node-selected').forEach(n => n.classList.remove('node-selected'));
            nodeEl.classList.add('node-selected');

            showEditPanel(container, editData, 'concept_node', (updatedData) => {
                nd.label = updatedData.text;
                nd.icon = updatedData.icon;
                if (updatedData.shape) nd.shape = updatedData.shape;
                rerenderFn();
            }, () => {
                nodeEl.classList.remove('node-selected');
            }, { nodeIndex: ni }, data, rerenderFn);
        });

        // Long-press on mobile triggers same edit flow
        addLongPress(svgEl, (e) => {
            const touch = e.touches && e.touches[0];
            const target = touch ? document.elementFromPoint(touch.clientX, touch.clientY) : e.target;
            if (target) {
                const node = target.closest ? target.closest('.concept-node') : null;
                if (node) node.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }));
            }
        });

        // Edit hint
        const toolbar = container.querySelector('.eduvisual-toolbar');
        if (toolbar && !toolbar.querySelector('.ev-edit-hint')) {
            const hint = document.createElement('span');
            hint.className = 'ev-toolbar-hint ev-edit-hint';
            hint.innerHTML = '<i class="fas fa-edit"></i> انقر مرتين على أي عنصر للتعديل';
            const firstGroup = toolbar.querySelector('.ev-toolbar-group');
            if (firstGroup) firstGroup.appendChild(hint);
        }
    }

    // ==========================================
    // 12.7 تحرير الملخصات البصرية (Visual Summary Editing)
    // ==========================================
    function enableSummaryEditing(container, data, rerenderFn) {
        const existingPanel = container.querySelector('.ev-edit-panel');
        if (existingPanel) existingPanel.remove();

        const svgEl = container.querySelector('svg');
        if (!svgEl) return;

        svgEl.addEventListener('dblclick', (e) => {
            const pointEl = e.target.closest('.summary-point');
            if (!pointEl) return;

            e.stopPropagation();
            e.preventDefault();

            const si = parseInt(pointEl.getAttribute('data-summary-index'));
            const pi = parseInt(pointEl.getAttribute('data-point-index'));
            if (isNaN(si) || isNaN(pi)) return;

            const summariesArr = Array.isArray(data) ? data : [data];
            if (si >= summariesArr.length) return;
            const summary = summariesArr[si];
            if (!summary.key_points || pi >= summary.key_points.length) return;
            const point = summary.key_points[pi];

            const editData = {
                text: point.point || '',
                icon: point.icon || '✅',
                color: summary.color || '#f59e0b',
                shape: 'rounded_rect',
                details: point.explanation || ''
            };

            svgEl.querySelectorAll('.node-selected').forEach(n => n.classList.remove('node-selected'));
            pointEl.classList.add('node-selected');

            showEditPanel(container, editData, 'sub', (updatedData) => {
                point.point = updatedData.text;
                point.icon = updatedData.icon;
                if (updatedData.details !== undefined) point.explanation = updatedData.details;
                rerenderFn();
            }, () => {
                pointEl.classList.remove('node-selected');
            }, { summaryIndex: si, pointIndex: pi }, Array.isArray(data) ? { summaries: data } : data, rerenderFn);
        });

        // Long-press on mobile triggers same edit flow
        addLongPress(svgEl, (e) => {
            const touch = e.touches && e.touches[0];
            const target = touch ? document.elementFromPoint(touch.clientX, touch.clientY) : e.target;
            if (target) {
                const node = target.closest ? target.closest('.summary-point') : null;
                if (node) node.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }));
            }
        });

        // Edit hint
        const toolbar = container.querySelector('.eduvisual-toolbar');
        if (toolbar && !toolbar.querySelector('.ev-edit-hint')) {
            const hint = document.createElement('span');
            hint.className = 'ev-toolbar-hint ev-edit-hint';
            hint.innerHTML = '<i class="fas fa-edit"></i> انقر مرتين على أي عنصر للتعديل';
            const firstGroup = toolbar.querySelector('.ev-toolbar-group');
            if (firstGroup) firstGroup.appendChild(hint);
        }
    }

    // ==========================================
    // 12.8 تحرير عام للخرائط الجديدة (Generic New Map Editing)
    // ==========================================
    function enableGenericEditing(container, data, rerenderFn, mapType) {
        const svgEl = container.querySelector('svg');
        if (!svgEl) return;

        svgEl.addEventListener('dblclick', (e) => {
            const nodeEl = e.target.closest('.interactive');
            if (!nodeEl) return;
            e.stopPropagation();
            e.preventDefault();

            let editData = null;
            let onSaveFn = null;

            if (mapType === 'fishbone') {
                const ci = nodeEl.getAttribute('data-cat-index');
                if (ci === null) return;
                const cat = (data.categories || [])[parseInt(ci)];
                if (!cat) return;
                editData = { text: cat.name || '', icon: cat.icon || '📋', color: cat.color || '#3b82f6', shape: 'rounded_rect' };
                onSaveFn = (u) => { cat.name = u.text; cat.icon = u.icon; cat.color = u.color; rerenderFn(); };
            } else if (mapType === 'hierarchy') {
                const ni = nodeEl.getAttribute('data-node-index');
                if (ni === null) return;
                const allN = [];
                (function flatten(n) { allN.push(n); (n.children || []).forEach(flatten); })(data.root);
                const node = allN[parseInt(ni)];
                if (!node) return;
                editData = { text: node.label || '', icon: node.icon || '📁', color: node.color || '#3b82f6', shape: 'rounded_rect' };
                onSaveFn = (u) => { node.label = u.text; node.icon = u.icon; node.color = u.color; rerenderFn(); };
            } else if (mapType === 'flowchart') {
                const si = nodeEl.getAttribute('data-step-index');
                if (si === null) return;
                const step = (data.steps || [])[parseInt(si)];
                if (!step) return;
                editData = { text: step.label || '', icon: step.icon || '', color: '#3b82f6', shape: 'rounded_rect' };
                onSaveFn = (u) => { step.label = u.text; step.icon = u.icon; rerenderFn(); };
            } else if (mapType === 'multiflow') {
                const ci2 = nodeEl.getAttribute('data-cause-index');
                const ei = nodeEl.getAttribute('data-effect-index');
                if (ci2 !== null) {
                    const cause = (data.causes || [])[parseInt(ci2)];
                    if (!cause) return;
                    const cLabel = typeof cause === 'string' ? cause : (cause.label || '');
                    editData = { text: cLabel, icon: typeof cause === 'object' ? (cause.icon || '🔹') : '🔹', color: '#3b82f6', shape: 'rounded_rect' };
                    onSaveFn = (u) => {
                        if (typeof data.causes[parseInt(ci2)] === 'string') data.causes[parseInt(ci2)] = u.text;
                        else { data.causes[parseInt(ci2)].label = u.text; data.causes[parseInt(ci2)].icon = u.icon; }
                        rerenderFn();
                    };
                } else if (ei !== null) {
                    const effect = (data.effects || [])[parseInt(ei)];
                    if (!effect) return;
                    const eLabel = typeof effect === 'string' ? effect : (effect.label || '');
                    editData = { text: eLabel, icon: typeof effect === 'object' ? (effect.icon || '💎') : '💎', color: '#10b981', shape: 'rounded_rect' };
                    onSaveFn = (u) => {
                        if (typeof data.effects[parseInt(ei)] === 'string') data.effects[parseInt(ei)] = u.text;
                        else { data.effects[parseInt(ei)].label = u.text; data.effects[parseInt(ei)].icon = u.icon; }
                        rerenderFn();
                    };
                } else return;
            } else if (mapType === 'pyramid') {
                const li = nodeEl.getAttribute('data-level-index');
                if (li === null) return;
                const level = (data.levels || [])[parseInt(li)];
                if (!level) return;
                editData = { text: level.label || '', icon: level.icon || '▸', color: level.color || '#3b82f6', shape: 'rounded_rect', details: level.description || '' };
                onSaveFn = (u) => { level.label = u.text; level.icon = u.icon; level.color = u.color; if (u.details !== undefined) level.description = u.details; rerenderFn(); };
            } else if (mapType === 'circlemap') {
                const ii = nodeEl.getAttribute('data-item-index');
                if (ii === null) return;
                const idx = parseInt(ii);
                const item = (data.context_items || [])[idx];
                if (item === undefined) return;
                const iLabel = typeof item === 'string' ? item : (item.text || item.label || '');
                editData = { text: iLabel, icon: typeof item === 'object' ? (item.icon || '') : '', color: '#3b82f6', shape: 'rounded_rect' };
                onSaveFn = (u) => {
                    if (typeof data.context_items[idx] === 'string') data.context_items[idx] = u.text;
                    else { data.context_items[idx].text = u.text; data.context_items[idx].icon = u.icon; }
                    rerenderFn();
                };
            } else if (mapType === 'venn') {
                // Venn diagram editing — items in set_a, set_b, or intersection
                const ai = nodeEl.getAttribute('data-venn-a-index');
                const bi = nodeEl.getAttribute('data-venn-b-index');
                const si = nodeEl.getAttribute('data-venn-shared-index');
                if (ai !== null) {
                    const idx = parseInt(ai);
                    const item = (data.set_a && data.set_a.items || [])[idx];
                    if (item === undefined) return;
                    const txt = typeof item === 'string' ? item : (item.text || '');
                    editData = { text: txt, icon: '', color: data.set_a.color || '#3b82f6', shape: 'rounded_rect' };
                    onSaveFn = (u) => { data.set_a.items[idx] = u.text; rerenderFn(); };
                } else if (bi !== null) {
                    const idx = parseInt(bi);
                    const item = (data.set_b && data.set_b.items || [])[idx];
                    if (item === undefined) return;
                    const txt = typeof item === 'string' ? item : (item.text || '');
                    editData = { text: txt, icon: '', color: data.set_b.color || '#10b981', shape: 'rounded_rect' };
                    onSaveFn = (u) => { data.set_b.items[idx] = u.text; rerenderFn(); };
                } else if (si !== null) {
                    const idx = parseInt(si);
                    const item = (data.intersection && data.intersection.items || [])[idx];
                    if (item === undefined) return;
                    const txt = typeof item === 'string' ? item : (item.text || '');
                    editData = { text: txt, icon: '', color: '#8b5cf6', shape: 'rounded_rect' };
                    onSaveFn = (u) => { data.intersection.items[idx] = u.text; rerenderFn(); };
                } else return;
            } else if (mapType === 'timeline') {
                const ei = nodeEl.getAttribute('data-event-index');
                if (ei === null) return;
                const evt = (data.events || [])[parseInt(ei)];
                if (!evt) return;
                editData = { text: evt.label || '', icon: evt.icon || '📅', color: evt.color || '#3b82f6', shape: 'rounded_rect', details: evt.description || '' };
                onSaveFn = (u) => { evt.label = u.text; evt.icon = u.icon; evt.color = u.color; if (u.details !== undefined) evt.description = u.details; rerenderFn(); };
            }

            if (!editData || !onSaveFn) return;

            svgEl.querySelectorAll('.node-selected').forEach(n => n.classList.remove('node-selected'));
            nodeEl.classList.add('node-selected');

            showEditPanel(container, editData, mapType === 'pyramid' ? 'sub' : 'branch', (updatedData) => {
                onSaveFn(updatedData);
            }, () => {
                nodeEl.classList.remove('node-selected');
            }, null, data, rerenderFn);
        });

        // Long-press on mobile triggers same edit flow
        addLongPress(svgEl, (e) => {
            const touch = e.touches && e.touches[0];
            const target = touch ? document.elementFromPoint(touch.clientX, touch.clientY) : e.target;
            if (target) {
                const node = target.closest ? target.closest('.interactive') : null;
                if (node) node.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }));
            }
        });

        // Edit hint
        const toolbar = container.querySelector('.eduvisual-toolbar');
        if (toolbar && !toolbar.querySelector('.ev-edit-hint')) {
            const hint = document.createElement('span');
            hint.className = 'ev-toolbar-hint ev-edit-hint';
            hint.innerHTML = '<i class="fas fa-edit"></i> انقر مرتين على أي عنصر للتعديل';
            const firstGroup = toolbar.querySelector('.ev-toolbar-group');
            if (firstGroup) firstGroup.appendChild(hint);
        }
    }
    function exportToPNG(svgElement, filename, scale) {
        filename = filename || 'mindmap.png';
        scale = scale || 4; // High quality export (4x)

        // Clone SVG and reset to original viewBox for full chart capture
        var clonedSvg = svgElement.cloneNode(true);
        var origVB = svgElement.getAttribute('data-original-viewbox');
        if (origVB) {
            clonedSvg.setAttribute('viewBox', origVB);
        }
        // Remove interactive classes that might affect rendering
        clonedSvg.style.cursor = 'default';

        var vb = clonedSvg.getAttribute('viewBox');
        var vbParts = vb ? vb.split(/\s+/).map(Number) : [0, 0, 800, 600];
        var vbW = vbParts[2] || 800;
        var vbH = vbParts[3] || 600;

        // Set explicit dimensions for rendering
        clonedSvg.setAttribute('width', vbW);
        clonedSvg.setAttribute('height', vbH);

        var svgData = new XMLSerializer().serializeToString(clonedSvg);
        var svgBlob = new Blob([svgData], { type: 'image/svg+xml;charset=utf-8' });
        var url = URL.createObjectURL(svgBlob);

        var img = new Image();
        img.onload = function() {
            var canvas = document.createElement('canvas');
            canvas.width = vbW * scale;
            canvas.height = vbH * scale;
            var ctx = canvas.getContext('2d');

            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

            canvas.toBlob(function(blob) {
                var a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = filename;
                a.click();
                URL.revokeObjectURL(a.href);
                showToast('تم تحميل الصورة بجودة عالية');
            }, 'image/png', 1.0);

            URL.revokeObjectURL(url);
        };
        img.onerror = function() {
            URL.revokeObjectURL(url);
            console.error('EduVisual: خطأ في تصدير PNG');
            showToast('حدث خطأ أثناء التصدير', 'warning');
        };
        img.src = url;
    }

    function exportToSVG(svgElement, filename) {
        filename = filename || 'mindmap.svg';
        // Clone SVG and reset to original viewBox for full chart export
        var clonedSvg = svgElement.cloneNode(true);
        var origVB = svgElement.getAttribute('data-original-viewbox');
        if (origVB) {
            clonedSvg.setAttribute('viewBox', origVB);
        }
        clonedSvg.style.cursor = 'default';
        var svgData = new XMLSerializer().serializeToString(clonedSvg);
        var blob = new Blob([svgData], { type: 'image/svg+xml;charset=utf-8' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = filename;
        a.click();
        URL.revokeObjectURL(a.href);
        showToast('تم تصدير ملف SVG');
    }

    /**
     * Copy SVG as PNG image to clipboard (Clipboard API)
     */
    function copyToClipboard(svgElement, scale) {
        scale = scale || 4; // High quality copy
        // Clone SVG and reset to original viewBox for full chart capture
        var clonedSvg = svgElement.cloneNode(true);
        var origVB = svgElement.getAttribute('data-original-viewbox');
        if (origVB) {
            clonedSvg.setAttribute('viewBox', origVB);
        }
        clonedSvg.style.cursor = 'default';

        var vb = clonedSvg.getAttribute('viewBox');
        var vbParts = vb ? vb.split(/\s+/).map(Number) : [0, 0, 800, 600];
        var vbW = vbParts[2] || 800;
        var vbH = vbParts[3] || 600;

        clonedSvg.setAttribute('width', vbW);
        clonedSvg.setAttribute('height', vbH);

        var svgData = new XMLSerializer().serializeToString(clonedSvg);
        var svgBlob = new Blob([svgData], { type: 'image/svg+xml;charset=utf-8' });
        var url = URL.createObjectURL(svgBlob);

        var img = new Image();
        img.onload = function() {
            var canvas = document.createElement('canvas');
            canvas.width = vbW * scale;
            canvas.height = vbH * scale;
            var ctx = canvas.getContext('2d');
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

            canvas.toBlob(function(blob) {
                if (navigator.clipboard && window.ClipboardItem) {
                    navigator.clipboard.write([
                        new ClipboardItem({ 'image/png': blob })
                    ]).then(function() {
                        showToast('تم نسخ الصورة للحافظة بجودة عالية');
                    }).catch(function() {
                        showToast('تعذر النسخ — جرب التصدير كـ PNG', 'warning');
                    });
                } else {
                    showToast('المتصفح لا يدعم النسخ للحافظة', 'warning');
                }
            }, 'image/png', 1.0);
            URL.revokeObjectURL(url);
        };
        img.onerror = function() { URL.revokeObjectURL(url); };
        img.src = url;
    }

    /**
     * Show a temporary toast notification
     */
    function showToast(message, type) {
        type = type || 'success';
        const existing = document.querySelector('.ev-toast');
        if (existing) existing.remove();

        const toast = document.createElement('div');
        toast.className = 'ev-toast ev-toast-' + type;
        toast.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'}"></i> ${escapeHtml(message)}`;
        document.body.appendChild(toast);
        requestAnimationFrame(() => toast.classList.add('visible'));
        setTimeout(() => {
            toast.classList.remove('visible');
            setTimeout(() => toast.remove(), 300);
        }, 2500);
    }

    /**
     * Export mind map data as JSON file
     */
    function exportJSON(data, filename) {
        filename = filename || 'eduvisual-map.json';
        const json = JSON.stringify(data, null, 2);
        const blob = new Blob([json], { type: 'application/json;charset=utf-8' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = filename;
        a.click();
        URL.revokeObjectURL(a.href);
    }

    /**
     * Import JSON and trigger re-render
     */
    function importJSON(callback) {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = '.json';
        input.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = (ev) => {
                try {
                    const data = JSON.parse(ev.target.result);
                    callback(data);
                    showToast('تم استيراد البيانات بنجاح');
                } catch (err) {
                    showToast('ملف JSON غير صالح', 'warning');
                }
            };
            reader.readAsText(file);
        });
        input.click();
    }

    // ==========================================
    // 14. أدوات مساعدة (Utilities)
    // ==========================================
    function shadeColor(color, percent) {
        const num = parseInt(color.replace('#', ''), 16);
        const amt = Math.round(2.55 * percent);
        const R = Math.max(0, Math.min(255, (num >> 16) + amt));
        const G = Math.max(0, Math.min(255, ((num >> 8) & 0x00FF) + amt));
        const B = Math.max(0, Math.min(255, (num & 0x0000FF) + amt));
        return '#' + (0x1000000 + R * 0x10000 + G * 0x100 + B).toString(16).slice(1);
    }

    const _escapeMap = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>"']/g, c => _escapeMap[c]);
    }

    // ==========================================
    // 15. شريط أدوات التحكم (Toolbar)
    // ==========================================
    function createToolbar(container, svgElement, options) {
        options = options || {};
        const {
            onThemeChange = null,
            currentTheme = 'modern',
            showExport = true,
            showThemes = true,
            showZoomHint = true,
            exportFilename = 'educore-visual'
        } = options;

        const toolbar = document.createElement('div');
        toolbar.className = 'eduvisual-toolbar';

        let html = '<div class="ev-toolbar-group">';

        if (showZoomHint) {
            html += `
                <span class="ev-toolbar-hint">
                    <i class="fas fa-mouse-pointer"></i>
                    اسحب للتحريك • عجلة الماوس للتكبير • انقر مرتين للإعادة
                </span>
                <span class="ev-toolbar-edit-hint" style="background: #e0e7ff; color: #4338ca; font-size: 0.78rem; font-weight: 600; padding: 4px 10px; border-radius: 8px; display: inline-flex; align-items: center; gap: 5px;">
                    <i class="fas fa-pen"></i>
                    انقر مرتين على أي عنصر للتعديل
                </span>
            `;
        }

        html += '</div><div class="ev-toolbar-group">';

        if (showThemes) {
            html += '<div class="ev-theme-selector">';
            for (const [key, themeObj] of Object.entries(THEMES)) {
                const active = key === currentTheme ? 'active' : '';
                const previewColor = themeObj.branchColors[0];
                html += `<button class="ev-theme-btn ${active}" data-theme="${key}" title="${themeObj.name}">
                    <span class="ev-theme-dot" style="background: ${previewColor}"></span>
                </button>`;
            }
            html += '</div>';
        }

        if (showExport) {
            html += `
                <button class="ev-export-btn" data-format="png" title="تصدير PNG">
                    <i class="fas fa-image"></i> PNG
                </button>
                <button class="ev-export-btn" data-format="svg" title="تصدير SVG">
                    <i class="fas fa-file-code"></i> SVG
                </button>
                <button class="ev-export-btn ev-copy-btn" data-format="copy" title="نسخ للحافظة">
                    <i class="fas fa-copy"></i>
                </button>
            `;
        }

        html += '</div>';
        toolbar.innerHTML = html;

        // Events
        toolbar.querySelectorAll('.ev-theme-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                toolbar.querySelectorAll('.ev-theme-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                if (onThemeChange) onThemeChange(btn.dataset.theme);
            });
        });

        toolbar.querySelectorAll('.ev-export-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                if (btn.dataset.format === 'png') {
                    exportToPNG(svgElement, exportFilename + '.png');
                } else if (btn.dataset.format === 'copy') {
                    copyToClipboard(svgElement);
                } else {
                    exportToSVG(svgElement, exportFilename + '.svg');
                }
            });
        });

        container.appendChild(toolbar);
        return toolbar;
    }

    // ==========================================
    // 15.5 اختصارات لوحة المفاتيح (Keyboard Shortcuts)
    // ==========================================
    function enableKeyboardShortcuts(container, data, rerenderFn) {
        // Only bind once per container
        if (container._evKbBound) return;
        container._evKbBound = true;

        container.addEventListener('keydown', (e) => {
            // Ignore when typing in inputs
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

            // Ctrl+Z — Undo
            if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !e.shiftKey) {
                e.preventDefault();
                if (UndoManager.undo()) showToast('تراجع');
                return;
            }
            // Ctrl+Y or Ctrl+Shift+Z — Redo
            if ((e.ctrlKey || e.metaKey) && (e.key === 'y' || (e.key === 'z' && e.shiftKey))) {
                e.preventDefault();
                if (UndoManager.redo()) showToast('إعادة');
                return;
            }
            // Escape — Close edit panel
            if (e.key === 'Escape') {
                closeEditPanel(container);
                return;
            }
        });

        // Make container focusable for keyboard events
        if (!container.getAttribute('tabindex')) {
            container.setAttribute('tabindex', '-1');
        }
    }

    // ==========================================
    // 16. الواجهة العامة (Public API)
    // ==========================================
    return {
        THEMES,

        /**
         * رسم خريطة ذهنية تفاعلية (مع تحرير)
         */
        mindMap(container, data, options) {
            options = options || {};
            const el = typeof container === 'string' ? document.getElementById(container) : container;
            if (!el || !data) return null;

            let currentTheme = options.theme || 'modern';

            const render = (theme) => {
                currentTheme = theme || currentTheme;
                const svgEl = renderMindMapSVG(el, data, { ...options, theme: currentTheme });
                const existingToolbar = el.querySelector('.eduvisual-toolbar');
                if (existingToolbar) existingToolbar.remove();
                createToolbar(el, svgEl, {
                    currentTheme: currentTheme,
                    onThemeChange: render,
                    exportFilename: 'mindmap-' + (data.central_node || 'visual').substring(0, 20)
                });
                UndoManager.init(data, () => render(currentTheme));
                // editRerender: غلاف حول render يُطلق options.onSave بعد كل إعادة رسم
                // ناتجة عن تعديل/إضافة/حذف/تراجع. تمييزاً عن تبديل السمة (الذي لا يُغيّر البيانات).
                // هذا يسمح لـ lesson_view.php بأن يطلب حفظ التعديلات في DB بعد كل تغيير.
                const editRerender = () => {
                    render(currentTheme);
                    if (typeof options.onSave === 'function') {
                        try { options.onSave(data); } catch (e) { console.error('onSave error:', e); }
                    }
                };
                enableEditing(el, data, editRerender);
                enableKeyboardShortcuts(el, data, editRerender);
                return svgEl;
            };

            return render(currentTheme);
        },

        /**
         * رسم مخطط فِن
         */
        vennDiagram(container, data, options) {
            options = options || {};
            const el = typeof container === 'string' ? document.getElementById(container) : container;
            if (!el || !data) return null;

            let currentTheme = options.theme || 'modern';

            const render = (theme) => {
                currentTheme = theme || currentTheme;
                const svgEl = renderVennDiagramSVG(el, data, { ...options, theme: currentTheme });
                const existingToolbar = el.querySelector('.eduvisual-toolbar');
                if (existingToolbar) existingToolbar.remove();
                createToolbar(el, svgEl, {
                    currentTheme: currentTheme,
                    onThemeChange: render,
                    exportFilename: 'venn-diagram'
                });
                UndoManager.init(data, () => render(currentTheme));
                enableGenericEditing(el, data, () => render(currentTheme), 'venn');
                enableKeyboardShortcuts(el, data, () => render(currentTheme));
                return svgEl;
            };

            return render(currentTheme);
        },

        /**
         * رسم خريطة مفاهيمية (legacy)
         */
        conceptMap(container, data, options) {
            options = options || {};
            const el = typeof container === 'string' ? document.getElementById(container) : container;
            if (!el || !data) return null;

            let currentTheme = options.theme || 'modern';

            const render = (theme) => {
                currentTheme = theme || currentTheme;
                const svgEl = renderConceptMapSVG(el, data, { ...options, theme: currentTheme });
                const existingToolbar = el.querySelector('.eduvisual-toolbar');
                if (existingToolbar) existingToolbar.remove();
                createToolbar(el, svgEl, {
                    currentTheme: currentTheme,
                    onThemeChange: render,
                    exportFilename: 'concept-map'
                });
                return svgEl;
            };

            return render(currentTheme);
        },

        /**
         * رسم ملخص بصري
         */
        summary(container, data, options) {
            options = options || {};
            const el = typeof container === 'string' ? document.getElementById(container) : container;
            if (!el || !data) return null;

            let currentTheme = options.theme || 'modern';

            const render = (theme) => {
                currentTheme = theme || currentTheme;
                const svgEl = renderVisualSummarySVG(el, data, { ...options, theme: currentTheme });
                const existingToolbar = el.querySelector('.eduvisual-toolbar');
                if (existingToolbar) existingToolbar.remove();
                createToolbar(el, svgEl, {
                    currentTheme: currentTheme,
                    onThemeChange: render,
                    showZoomHint: false,
                    exportFilename: 'visual-summary'
                });
                enableSummaryEditing(el, data, () => render(currentTheme));
                enableKeyboardShortcuts(el, data, () => render(currentTheme));
                return svgEl;
            };

            return render(currentTheme);
        },

        /**
         * رسم خط زمني
         */
        timeline(container, data, options) {
            options = options || {};
            const el = typeof container === 'string' ? document.getElementById(container) : container;
            if (!el || !data) return null;
            let currentTheme = options.theme || 'modern';
            const render = (theme) => {
                currentTheme = theme || currentTheme;
                const svgEl = renderTimelineSVG(el, data, { ...options, theme: currentTheme });
                const existingToolbar = el.querySelector('.eduvisual-toolbar');
                if (existingToolbar) existingToolbar.remove();
                createToolbar(el, svgEl, { currentTheme, onThemeChange: render, exportFilename: 'timeline' });
                UndoManager.init(data, () => render(currentTheme));
                enableGenericEditing(el, data, () => render(currentTheme), 'timeline');
                enableKeyboardShortcuts(el, data, () => render(currentTheme));
                return svgEl;
            };
            return render(currentTheme);
        },

        /**
         * رسم خريطة عظمة السمكة (legacy)
         */
        fishbone(container, data, options) {
            options = options || {};
            const el = typeof container === 'string' ? document.getElementById(container) : container;
            if (!el || !data) return null;
            let currentTheme = options.theme || 'modern';
            const render = (theme) => {
                currentTheme = theme || currentTheme;
                const svgEl = renderFishboneSVG(el, data, { ...options, theme: currentTheme });
                const existingToolbar = el.querySelector('.eduvisual-toolbar');
                if (existingToolbar) existingToolbar.remove();
                createToolbar(el, svgEl, { currentTheme, onThemeChange: render, exportFilename: 'fishbone-map' });
                return svgEl;
            };
            return render(currentTheme);
        },

        /**
         * رسم خريطة هيكلية
         */
        hierarchy(container, data, options) {
            options = options || {};
            const el = typeof container === 'string' ? document.getElementById(container) : container;
            if (!el || !data || !data.root) return null;
            let currentTheme = options.theme || 'modern';
            const render = (theme) => {
                currentTheme = theme || currentTheme;
                const svgEl = renderHierarchySVG(el, data, { ...options, theme: currentTheme });
                const existingToolbar = el.querySelector('.eduvisual-toolbar');
                if (existingToolbar) existingToolbar.remove();
                createToolbar(el, svgEl, { currentTheme, onThemeChange: render, exportFilename: 'hierarchy-map' });
                UndoManager.init(data, () => render(currentTheme));
                enableGenericEditing(el, data, () => render(currentTheme), 'hierarchy');
                enableKeyboardShortcuts(el, data, () => render(currentTheme));
                return svgEl;
            };
            return render(currentTheme);
        },

        /**
         * رسم خريطة تدفق
         */
        flowchart(container, data, options) {
            options = options || {};
            const el = typeof container === 'string' ? document.getElementById(container) : container;
            if (!el || !data) return null;
            let currentTheme = options.theme || 'modern';
            const render = (theme) => {
                currentTheme = theme || currentTheme;
                const svgEl = renderFlowchartSVG(el, data, { ...options, theme: currentTheme });
                const existingToolbar = el.querySelector('.eduvisual-toolbar');
                if (existingToolbar) existingToolbar.remove();
                createToolbar(el, svgEl, { currentTheme, onThemeChange: render, exportFilename: 'flowchart' });
                UndoManager.init(data, () => render(currentTheme));
                enableGenericEditing(el, data, () => render(currentTheme), 'flowchart');
                enableKeyboardShortcuts(el, data, () => render(currentTheme));
                return svgEl;
            };
            return render(currentTheme);
        },

        /**
         * رسم خريطة الأسباب والنتائج
         */
        multiFlow(container, data, options) {
            options = options || {};
            const el = typeof container === 'string' ? document.getElementById(container) : container;
            if (!el || !data) return null;
            let currentTheme = options.theme || 'modern';
            const render = (theme) => {
                currentTheme = theme || currentTheme;
                const svgEl = renderMultiFlowSVG(el, data, { ...options, theme: currentTheme });
                const existingToolbar = el.querySelector('.eduvisual-toolbar');
                if (existingToolbar) existingToolbar.remove();
                createToolbar(el, svgEl, { currentTheme, onThemeChange: render, exportFilename: 'multi-flow-map' });
                UndoManager.init(data, () => render(currentTheme));
                enableGenericEditing(el, data, () => render(currentTheme), 'multiflow');
                enableKeyboardShortcuts(el, data, () => render(currentTheme));
                return svgEl;
            };
            return render(currentTheme);
        },

        /**
         * رسم خريطة هرمية
         */
        pyramid(container, data, options) {
            options = options || {};
            const el = typeof container === 'string' ? document.getElementById(container) : container;
            if (!el || !data) return null;
            let currentTheme = options.theme || 'modern';
            const render = (theme) => {
                currentTheme = theme || currentTheme;
                const svgEl = renderPyramidSVG(el, data, { ...options, theme: currentTheme });
                const existingToolbar = el.querySelector('.eduvisual-toolbar');
                if (existingToolbar) existingToolbar.remove();
                createToolbar(el, svgEl, { currentTheme, onThemeChange: render, exportFilename: 'pyramid-map' });
                UndoManager.init(data, () => render(currentTheme));
                enableGenericEditing(el, data, () => render(currentTheme), 'pyramid');
                enableKeyboardShortcuts(el, data, () => render(currentTheme));
                return svgEl;
            };
            return render(currentTheme);
        },

        /**
         * رسم خريطة دائرية
         */
        circleMap(container, data, options) {
            options = options || {};
            const el = typeof container === 'string' ? document.getElementById(container) : container;
            if (!el || !data) return null;
            let currentTheme = options.theme || 'modern';
            const render = (theme) => {
                currentTheme = theme || currentTheme;
                const svgEl = renderCircleMapSVG(el, data, { ...options, theme: currentTheme });
                const existingToolbar = el.querySelector('.eduvisual-toolbar');
                if (existingToolbar) existingToolbar.remove();
                createToolbar(el, svgEl, { currentTheme, onThemeChange: render, exportFilename: 'circle-map' });
                UndoManager.init(data, () => render(currentTheme));
                enableGenericEditing(el, data, () => render(currentTheme), 'circlemap');
                enableKeyboardShortcuts(el, data, () => render(currentTheme));
                return svgEl;
            };
            return render(currentTheme);
        },

        /**
         * رسم خريطة دورية
         */
        cycleMap(container, data, options) {
            options = options || {};
            const el = typeof container === 'string' ? document.getElementById(container) : container;
            if (!el || !data) return null;
            let currentTheme = options.theme || 'modern';
            const render = (theme) => {
                currentTheme = theme || currentTheme;
                const svgEl = renderCycleMapSVG(el, data, { ...options, theme: currentTheme });
                const existingToolbar = el.querySelector('.eduvisual-toolbar');
                if (existingToolbar) existingToolbar.remove();
                createToolbar(el, svgEl, { currentTheme, onThemeChange: render, exportFilename: 'cycle-map' });
                return svgEl;
            };
            return render(currentTheme);
        },

        /**
         * رسم جدول مقارنة
         */
        comparisonTable(container, data, options) {
            options = options || {};
            const el = typeof container === 'string' ? document.getElementById(container) : container;
            if (!el || !data) return null;
            let currentTheme = options.theme || 'modern';
            const render = (theme) => {
                currentTheme = theme || currentTheme;
                const svgEl = renderComparisonTableSVG(el, data, { ...options, theme: currentTheme });
                const existingToolbar = el.querySelector('.eduvisual-toolbar');
                if (existingToolbar) existingToolbar.remove();
                createToolbar(el, svgEl, { currentTheme, onThemeChange: render, exportFilename: 'comparison-table' });
                return svgEl;
            };
            return render(currentTheme);
        },

        /**
         * رسم كل الأقسام (الخريطة الذهنية + المفاهيمية + الملخص + الجديدة)
         */
        renderAll(containerId, mindMapsData, options) {
            options = options || {};
            const wrapper = typeof containerId === 'string' ? document.getElementById(containerId) : containerId;
            if (!wrapper || !mindMapsData) return;

            // Input validation — ensure data is an object
            if (typeof mindMapsData !== 'object') {
                console.error('EduVisual: mindMapsData must be an object, got:', typeof mindMapsData);
                return;
            }

            wrapper.innerHTML = '';
            wrapper.classList.add('eduvisual-wrapper');

            const self = this;
            const pendingRenders = [];

            // Helper — create section DOM but defer rendering
            function addSection(label, sectionHtml, renderFn) {
                const section = document.createElement('div');
                section.className = 'eduvisual-section';
                section.innerHTML = sectionHtml;
                wrapper.appendChild(section);
                pendingRenders.push({ section, label, renderFn, rendered: false });
            }

            // 1. Main Mind Map
            if (mindMapsData.main_mind_map && typeof mindMapsData.main_mind_map === 'object') {
                addSection('الخريطة الذهنية الرئيسية', `
                    <div class="eduvisual-section-header">
                        <h4><i class="fas fa-sitemap"></i> الخريطة الذهنية الرئيسية</h4>
                    </div>
                    <div class="eduvisual-canvas ev-lazy-placeholder" id="ev-mindmap-canvas">
                        <div class="ev-lazy-loading"><i class="fas fa-spinner fa-pulse"></i></div>
                    </div>
                `, () => self.mindMap('ev-mindmap-canvas', mindMapsData.main_mind_map, options));
            }

            // 2. Fishbone Maps (replaces concept maps) — or legacy Venn/Cycle Diagrams (backward compat)
            // concept_maps removed from generation - kept only as legacy fallback
            if (Array.isArray(mindMapsData.venn_diagrams) && mindMapsData.venn_diagrams.length > 0) {
                mindMapsData.venn_diagrams.forEach((vd, i) => {
                    if (!vd || typeof vd !== 'object') return;
                    const canvasId = `ev-venn-canvas-${i}`;
                    addSection(`مخطط فِن ${i + 1}`, `
                        <div class="eduvisual-section-header venn">
                            <h4><i class="fas fa-circle-notch"></i> ${escapeHtml(vd.title || 'مخطط فِن')}</h4>
                            ${vd.description ? `<p>${escapeHtml(vd.description)}</p>` : ''}
                        </div>
                        <div class="eduvisual-canvas short ev-lazy-placeholder" id="${canvasId}">
                            <div class="ev-lazy-loading"><i class="fas fa-spinner fa-pulse"></i></div>
                        </div>
                    `, () => self.vennDiagram(canvasId, vd, options));
                });
            }

            // 2b. Fishbone Maps (primary) — or legacy Cycle Maps
            if (Array.isArray(mindMapsData.fishbone_maps) && mindMapsData.fishbone_maps.length > 0) {
                mindMapsData.fishbone_maps.forEach((fb, i) => {
                    if (!fb || typeof fb !== 'object') return;
                    const canvasId = `ev-fishbone-canvas-${i}`;
                    addSection(`خريطة عظمة السمكة ${i + 1}`, `
                        <div class="eduvisual-section-header fishbone">
                            <h4><i class="fas fa-fish"></i> ${escapeHtml(fb.title || 'خريطة عظمة السمكة')}</h4>
                            ${fb.description ? `<p>${escapeHtml(fb.description)}</p>` : ''}
                        </div>
                        <div class="eduvisual-canvas ev-lazy-placeholder" id="${canvasId}">
                            <div class="ev-lazy-loading"><i class="fas fa-spinner fa-pulse"></i></div>
                        </div>
                    `, () => self.fishbone(canvasId, fb, options));
                });
            } else if (Array.isArray(mindMapsData.cycle_maps) && mindMapsData.cycle_maps.length > 0) {
                mindMapsData.cycle_maps.forEach((cm, i) => {
                    if (!cm || typeof cm !== 'object') return;
                    const canvasId = `ev-cycle-canvas-${i}`;
                    addSection(`الخريطة الدورية ${i + 1}`, `
                        <div class="eduvisual-section-header cycle">
                            <h4><i class="fas fa-sync-alt"></i> ${escapeHtml(cm.title || 'الخريطة الدورية')}</h4>
                            ${cm.description ? `<p>${escapeHtml(cm.description)}</p>` : ''}
                        </div>
                        <div class="eduvisual-canvas ev-lazy-placeholder" id="${canvasId}">
                            <div class="ev-lazy-loading"><i class="fas fa-spinner fa-pulse"></i></div>
                        </div>
                    `, () => self.cycleMap(canvasId, cm, options));
                });
            }

            // 3. Timeline Maps
            if (Array.isArray(mindMapsData.timeline_maps) && mindMapsData.timeline_maps.length > 0) {
                mindMapsData.timeline_maps.forEach((tl, i) => {
                    if (!tl || typeof tl !== 'object') return;
                    const canvasId = `ev-timeline-canvas-${i}`;
                    addSection(`الخط الزمني ${i + 1}`, `
                        <div class="eduvisual-section-header timeline">
                            <h4><i class="fas fa-clock"></i> ${escapeHtml(tl.title || 'الخط الزمني')}</h4>
                            ${tl.description ? `<p>${escapeHtml(tl.description)}</p>` : ''}
                        </div>
                        <div class="eduvisual-canvas ev-lazy-placeholder" id="${canvasId}">
                            <div class="ev-lazy-loading"><i class="fas fa-spinner fa-pulse"></i></div>
                        </div>
                    `, () => self.timeline(canvasId, tl, options));
                });
            }

            // 4. Hierarchy Maps
            if (Array.isArray(mindMapsData.hierarchy_maps) && mindMapsData.hierarchy_maps.length > 0) {
                mindMapsData.hierarchy_maps.forEach((hm, i) => {
                    if (!hm || typeof hm !== 'object') return;
                    const canvasId = `ev-hierarchy-canvas-${i}`;
                    addSection(`الخريطة الهيكلية ${i + 1}`, `
                        <div class="eduvisual-section-header hierarchy">
                            <h4><i class="fas fa-stream"></i> ${escapeHtml(hm.title || 'الخريطة الهيكلية')}</h4>
                            ${hm.description ? `<p>${escapeHtml(hm.description)}</p>` : ''}
                        </div>
                        <div class="eduvisual-canvas ev-lazy-placeholder" id="${canvasId}">
                            <div class="ev-lazy-loading"><i class="fas fa-spinner fa-pulse"></i></div>
                        </div>
                    `, () => self.hierarchy(canvasId, hm, options));
                });
            }

            // 5. Flowchart Maps
            if (Array.isArray(mindMapsData.flowchart_maps) && mindMapsData.flowchart_maps.length > 0) {
                mindMapsData.flowchart_maps.forEach((fc, i) => {
                    if (!fc || typeof fc !== 'object') return;
                    const canvasId = `ev-flowchart-canvas-${i}`;
                    addSection(`خريطة التدفق ${i + 1}`, `
                        <div class="eduvisual-section-header flowchart">
                            <h4><i class="fas fa-project-diagram"></i> ${escapeHtml(fc.title || 'خريطة التدفق')}</h4>
                            ${fc.description ? `<p>${escapeHtml(fc.description)}</p>` : ''}
                        </div>
                        <div class="eduvisual-canvas ev-lazy-placeholder" id="${canvasId}">
                            <div class="ev-lazy-loading"><i class="fas fa-spinner fa-pulse"></i></div>
                        </div>
                    `, () => self.flowchart(canvasId, fc, options));
                });
            }

            // 6. Multi-Flow Maps
            if (Array.isArray(mindMapsData.multi_flow_maps) && mindMapsData.multi_flow_maps.length > 0) {
                mindMapsData.multi_flow_maps.forEach((mf, i) => {
                    if (!mf || typeof mf !== 'object') return;
                    const canvasId = `ev-multiflow-canvas-${i}`;
                    addSection(`خريطة الأسباب والنتائج ${i + 1}`, `
                        <div class="eduvisual-section-header multiflow">
                            <h4><i class="fas fa-exchange-alt"></i> ${escapeHtml(mf.title || 'خريطة الأسباب والنتائج')}</h4>
                            ${mf.description ? `<p>${escapeHtml(mf.description)}</p>` : ''}
                        </div>
                        <div class="eduvisual-canvas ev-lazy-placeholder" id="${canvasId}">
                            <div class="ev-lazy-loading"><i class="fas fa-spinner fa-pulse"></i></div>
                        </div>
                    `, () => self.multiFlow(canvasId, mf, options));
                });
            }

            // 7. Pyramid Maps
            if (Array.isArray(mindMapsData.pyramid_maps) && mindMapsData.pyramid_maps.length > 0) {
                mindMapsData.pyramid_maps.forEach((pm, i) => {
                    if (!pm || typeof pm !== 'object') return;
                    const canvasId = `ev-pyramid-canvas-${i}`;
                    addSection(`خريطة الهرم ${i + 1}`, `
                        <div class="eduvisual-section-header pyramid">
                            <h4><i class="fas fa-sort-amount-up"></i> ${escapeHtml(pm.title || 'خريطة الهرم')}</h4>
                            ${pm.description ? `<p>${escapeHtml(pm.description)}</p>` : ''}
                        </div>
                        <div class="eduvisual-canvas short ev-lazy-placeholder" id="${canvasId}">
                            <div class="ev-lazy-loading"><i class="fas fa-spinner fa-pulse"></i></div>
                        </div>
                    `, () => self.pyramid(canvasId, pm, options));
                });
            }

            // 8. Circle Maps
            if (Array.isArray(mindMapsData.circle_maps) && mindMapsData.circle_maps.length > 0) {
                mindMapsData.circle_maps.forEach((cm, i) => {
                    if (!cm || typeof cm !== 'object') return;
                    const canvasId = `ev-circlemap-canvas-${i}`;
                    addSection(`الخريطة الدائرية ${i + 1}`, `
                        <div class="eduvisual-section-header circlemap">
                            <h4><i class="fas fa-bullseye"></i> ${escapeHtml(cm.title || 'الخريطة الدائرية')}</h4>
                            ${cm.description ? `<p>${escapeHtml(cm.description)}</p>` : ''}
                        </div>
                        <div class="eduvisual-canvas ev-lazy-placeholder" id="${canvasId}">
                            <div class="ev-lazy-loading"><i class="fas fa-spinner fa-pulse"></i></div>
                        </div>
                    `, () => self.circleMap(canvasId, cm, options));
                });
            }

            // 9. Visual Summaries (last)
            if (Array.isArray(mindMapsData.visual_summaries) && mindMapsData.visual_summaries.length > 0) {
                addSection('الملخصات البصرية', `
                    <div class="eduvisual-section-header summary">
                        <h4><i class="fas fa-clipboard-list"></i> الملخصات البصرية</h4>
                    </div>
                    <div class="eduvisual-canvas auto-height ev-lazy-placeholder" id="ev-summary-canvas">
                        <div class="ev-lazy-loading"><i class="fas fa-spinner fa-pulse"></i></div>
                    </div>
                `, () => self.summary('ev-summary-canvas', mindMapsData.visual_summaries, options));
            }

            if (pendingRenders.length === 0) {
                wrapper.innerHTML = '<div style="padding:40px;text-align:center;color:#94a3b8;"><i class="fas fa-info-circle"></i> لا تتوفر بيانات خرائط صالحة للعرض</div>';
                return;
            }

            // Lazy rendering: render first 2 immediately, rest on intersection
            const EAGER_COUNT = 2;
            pendingRenders.forEach((item, idx) => {
                if (idx < EAGER_COUNT) {
                    try { item.renderFn(); item.rendered = true; } catch (err) {
                        console.error(`EduVisual: خطأ في رسم ${item.label}:`, err);
                    }
                }
            });

            // IntersectionObserver for the rest
            if (pendingRenders.length > EAGER_COUNT && 'IntersectionObserver' in window) {
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (!entry.isIntersecting) return;
                        const item = pendingRenders.find(p => p.section === entry.target);
                        if (item && !item.rendered) {
                            try { item.renderFn(); item.rendered = true; } catch (err) {
                                console.error(`EduVisual: خطأ في رسم ${item.label}:`, err);
                            }
                            observer.unobserve(entry.target);
                        }
                    });
                }, { rootMargin: '200px' });

                pendingRenders.forEach((item, idx) => {
                    if (idx >= EAGER_COUNT) observer.observe(item.section);
                });
            } else {
                // Fallback: render all
                pendingRenders.forEach(item => {
                    if (!item.rendered) {
                        try { item.renderFn(); item.rendered = true; } catch (err) {
                            console.error(`EduVisual: خطأ في رسم ${item.label}:`, err);
                        }
                    }
                });
            }
        },

        exportPNG: exportToPNG,
        exportSVG: exportToSVG,
        copyToClipboard,
        exportJSON,
        importJSON,
        showToast,
        UndoManager
    };
})();

// Expose to window for global access
window.EduVisual = EduVisual;
