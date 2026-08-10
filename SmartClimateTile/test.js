
        // Initiale Daten als JS-String-Literal (htmlspecialchars in PHP, .replace() hier)
        let rawData = '__INITIAL_DATA__';
        let state = {};

        try {
            const decoded = rawData
                .replace(/&quot;/g, '"')
                .replace(/&#039;/g, "'")
                .replace(/&amp;/g, '&')
                .replace(/&lt;/g, '<')
                .replace(/&gt;/g, '>');
            state = JSON.parse(decoded);
        } catch (e) {
            console.error('SCT: Parse Error', e);
        }

        // Config-Defaults sicherstellen
        if (!state.config) state.config = {};
        if (!state.config.tempMin) state.config.tempMin = 5.0;
        if (!state.config.tempMax || state.config.tempMax <= state.config.tempMin) state.config.tempMax = 30.0;
        if (!state.config.tempStep) state.config.tempStep = 0.5;
        if (state.config.colorCold === undefined) state.config.colorCold = 0x3B82F6;
        if (state.config.colorWarm === undefined) state.config.colorWarm = 0xEF4444;
        if (state.config.gaugeStyle === undefined) state.config.gaugeStyle = 0;

        const ICONS = {
            'Climate': '<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm3-11v2H9v-2h6z"/>',
            'Window': '<path d="M11 20v-7H3v7h8zm2 0h8v-7h-8v7zm-2-9V4H3v7h8zm2 0h8V4h-8v7z"/>',
            'Fire': '<path d="M17.58,4.09L15.34,6.33L18.4,9.39L16.27,11.5L13.1,8.33L10.97,10.46L14.04,13.53L11.91,15.65L8.74,12.47L6.61,14.61L9.68,17.68L7.43,19.92C5.96,18.46 5,16.42 5,14.17C5,9.65 8.65,6 13.17,6C14.88,6 16.44,6.5 17.7,7.31L18.96,6.05C18.66,5.32 18.17,4.65 17.58,4.09M22.03,12.18C22.03,17.06 18.06,21.03 13.18,21.03C11.5,21.03 9.96,20.5 8.7,19.65L10.16,18.18L13.23,21.25L15.35,19.13L12.28,16.05L14.41,13.92L17.47,17L19.6,14.87L16.53,11.8L18.66,9.67L21.72,12.74L23.95,10.5C23,8 21.36,5.92 19.2,4.45L17.74,5.91C20.35,7.03 22.03,9.45 22.03,12.18Z"/>',
            'default': '<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/>'
        };

        // Gauge Settings
        const GAUGE_CX = 100;
        const GAUGE_CY = 100;
        const GAUGE_R = 80;
        let isDragging = false;
        
        // Element Refs – lazy getters, damit DOM sicher fertig ist
        const els = {
            get svg()              { return document.getElementById('svgGauge'); },
            get bgPath()          { return document.getElementById('gaugeBgPath'); },
            get valPath()         { return document.getElementById('gaugeValuePath'); },
            get handle()          { return document.getElementById('gaugeHandle'); },
            get tempActual()      { return document.getElementById('tempActual'); },
            get tempTarget()      { return document.getElementById('tempTarget'); },
            get humidityWrapper() { return document.getElementById('humidityWrapper'); },
            get humidityVal()     { return document.getElementById('humidityVal'); },
            get valveWrapper()    { return document.getElementById('valveWrapper'); },
            get valveVal()        { return document.getElementById('valveVal'); },
            get statusIndicators(){ return document.getElementById('statusIndicators'); },
            get modesContainer()  { return document.getElementById('modesContainer'); },
            get glowBg()          { return document.getElementById('glowBg'); },
            get gradStart()       { return document.getElementById('gradStart'); },
            get gradEnd()         { return document.getElementById('gradEnd'); },
            get btnMinus()        { return document.getElementById('btnMinus'); },
            get btnPlus()         { return document.getElementById('btnPlus'); }
        };

        // Utility: Int to Hex Color
        function intToHex(intColor) {
            let hex = Math.abs(intColor).toString(16);
            while (hex.length < 6) hex = "0" + hex;
            return "#" + hex;
        }

        // Utility: Wert setzen (sofort + optional animiert)
        function animateValue(obj, start, end, duration, decimals = 1) {
            if (!obj) return;
            const isActual = (obj === els.tempActual);
            const unit = isActual ? '<span class="unit">°C</span>' : '';

            // Sofort den Endwert setzen (kein rAF-Dependency fuer ersten Render)
            const endVal = isNaN(end) ? 0 : end;
            obj.innerHTML = endVal.toFixed(decimals) + unit;

            // Optional: sanfte Animation wenn Start bekannt und rAF verfuegbar
            const startVal = isNaN(start) ? endVal : start;
            if (Math.abs(endVal - startVal) < 0.05 || !window.requestAnimationFrame) return;

            let startTimestamp = null;
            const step = (timestamp) => {
                if (!startTimestamp) startTimestamp = timestamp;
                const progress = Math.min((timestamp - startTimestamp) / duration, 1);
                const easeProgress = progress * (2 - progress);
                const current = startVal + (endVal - startVal) * easeProgress;
                obj.innerHTML = current.toFixed(decimals) + unit;
                if (progress < 1) window.requestAnimationFrame(step);
                else obj.innerHTML = endVal.toFixed(decimals) + unit;
            };
            window.requestAnimationFrame(step);
        }

        // Gauge Math
        function getGaugeAngles(style) {
            switch(style) {
                case 1: return { start: -90, end: 90 };     // 180°
                case 2: return { start: 0, end: 359.99 };   // 360°
                case 0: 
                default: return { start: -135, end: 135 };  // 270°
            }
        }

        function polarToCartesian(centerX, centerY, radius, angleInDegrees) {
            const angleInRadians = (angleInDegrees - 90) * Math.PI / 180.0;
            return {
                x: centerX + (radius * Math.cos(angleInRadians)),
                y: centerY + (radius * Math.sin(angleInRadians))
            };
        }

        function describeArc(x, y, radius, startAngle, endAngle) {
            const start = polarToCartesian(x, y, radius, endAngle);
            const end = polarToCartesian(x, y, radius, startAngle);
            const largeArcFlag = endAngle - startAngle <= 180 ? "0" : "1";
            return [
                "M", start.x, start.y, 
                "A", radius, radius, 0, largeArcFlag, 0, end.x, end.y
            ].join(" ");
        }

        function valueToAngle(val) {
            const angles = getGaugeAngles(state.config.gaugeStyle);
            const range = state.config.tempMax - state.config.tempMin;
            const percentage = Math.max(0, Math.min(1, (val - state.config.tempMin) / range));
            return angles.start + (percentage * (angles.end - angles.start));
        }

        function angleToValue(angle) {
            const angles = getGaugeAngles(state.config.gaugeStyle);
            let normalizedAngle = angle;
            
            // Normalize for 360 mode if needed
            if (state.config.gaugeStyle === 2) {
                if (normalizedAngle < 0) normalizedAngle += 360;
            }
            
            let percentage = (normalizedAngle - angles.start) / (angles.end - angles.start);
            percentage = Math.max(0, Math.min(1, percentage));
            
            let val = state.config.tempMin + (percentage * (state.config.tempMax - state.config.tempMin));
            
            // Snap to step
            const step = state.config.tempStep;
            val = Math.round(val / step) * step;
            return val;
        }

        function getAngleFromEvent(e) {
            const rect = els.svg.getBoundingClientRect();
            const clientX = e.touches ? e.touches[0].clientX : e.clientX;
            const clientY = e.touches ? e.touches[0].clientY : e.clientY;
            
            const x = clientX - rect.left - (rect.width / 2);
            const y = clientY - rect.top - (rect.height / 2);
            
            let angle = Math.atan2(y, x) * 180 / Math.PI + 90;
            if (angle > 180 && state.config.gaugeStyle !== 2) angle -= 360;
            return angle;
        }

        // Render functions
        function renderGaugeStatic() {
            const angles = getGaugeAngles(state.config.gaugeStyle);
            els.bgPath.setAttribute('d', describeArc(GAUGE_CX, GAUGE_CY, GAUGE_R, angles.start, angles.end));
            
            els.gradStart.setAttribute('stop-color', intToHex(state.config.colorCold));
            els.gradEnd.setAttribute('stop-color', intToHex(state.config.colorWarm));
            
            if (state.config.gaugeStyle === 1) { // 180° - move center display up a bit
                document.querySelector('.center-display').style.top = '40%';
            } else {
                document.querySelector('.center-display').style.top = '50%';
            }
        }

        function updateGaugeValue(temp, animate = true) {
            if (!animate) els.valPath.style.transition = 'none';
            
            const angles = getGaugeAngles(state.config.gaugeStyle);
            const endAngle = valueToAngle(temp);
            
            if (Math.abs(endAngle - angles.start) > 0.1) {
                els.valPath.setAttribute('d', describeArc(GAUGE_CX, GAUGE_CY, GAUGE_R, angles.start, endAngle));
                els.valPath.style.display = 'block';
            } else {
                els.valPath.style.display = 'none';
            }
            
            const handlePos = polarToCartesian(GAUGE_CX, GAUGE_CY, GAUGE_R, endAngle);
            els.handle.setAttribute('cx', handlePos.x);
            els.handle.setAttribute('cy', handlePos.y);
            
            els.tempTarget.textContent = 'Soll: ' + temp.toFixed(1) + '°';
            
            if (!animate) {
                // Force reflow to clear transition
                void els.valPath.offsetWidth;
                els.valPath.style.transition = '';
            }
        }

        function renderState(newState, oldState = {}) {
            // Static config updates
            if (!oldState.config || JSON.stringify(newState.config) !== JSON.stringify(oldState.config)) {
                renderGaugeStatic();
            }

            // Actual Temp
            if (newState.actualTemp !== oldState.actualTemp) {
                const oldT = oldState.actualTemp || newState.actualTemp;
                animateValue(els.tempActual, oldT, newState.actualTemp, 500, 1);
            }

            // Target Temp (only update if not dragging)
            if (newState.targetTemp !== oldState.targetTemp && !isDragging) {
                updateGaugeValue(newState.targetTemp);
            }

            // Humidity & Valve
            if (newState.hasHumidity) {
                els.humidityWrapper.classList.remove('hidden');
                els.humidityVal.textContent = newState.humidity + '%';
            } else {
                els.humidityWrapper.classList.add('hidden');
            }

            if (newState.hasValve) {
                els.valveWrapper.classList.remove('hidden');
                els.valveVal.textContent = newState.valvePosition + '%';
                
                // Glow effect proportional to valve position
                if (newState.valvePosition > 0) {
                    const alpha = Math.min(0.6, newState.valvePosition / 100);
                    const hexColor = intToHex(state.config.colorWarm);
                    // Convert hex to rgb for alpha
                    const r = parseInt(hexColor.slice(1,3), 16);
                    const g = parseInt(hexColor.slice(3,5), 16);
                    const b = parseInt(hexColor.slice(5,7), 16);
                    els.glowBg.style.background = `radial-gradient(circle, rgba(${r},${g},${b},${alpha}) 0%, transparent 70%)`;
                } else {
                    els.glowBg.style.background = 'transparent';
                }
            } else {
                els.valveWrapper.classList.add('hidden');
                els.glowBg.style.background = 'transparent';
            }

            // Status Indicators
            if (JSON.stringify(newState.statusIndicators) !== JSON.stringify(oldState.statusIndicators)) {
                els.statusIndicators.innerHTML = '';
                (newState.statusIndicators || []).forEach(ind => {
                    const div = document.createElement('div');
                    div.className = 'status-indicator pulse';
                    if (ind.active) {
                        div.classList.add('active');
                        const hex = intToHex(ind.color);
                        div.style.setProperty('--status-color-bg', hex + '33'); // 20% opacity
                        div.style.setProperty('--status-color-fg', hex);
                    }
                    const iconPath = ICONS[ind.icon] || ICONS['default'];
                    div.innerHTML = `<svg viewBox="0 0 24 24">${iconPath}</svg>` + (ind.label ? `<span>${ind.label}</span>` : '');
                    els.statusIndicators.appendChild(div);
                });
            }

            // Modes
            if (JSON.stringify(newState.modes) !== JSON.stringify(oldState.modes) || newState.activeMode !== oldState.activeMode) {
                els.modesContainer.innerHTML = '';
                (newState.modes || []).forEach(mode => {
                    const btn = document.createElement('button');
                    // Loose-Vergleich: "AUTOMATIC" == "AUTOMATIC", 0 == "0" etc.
                    const isActive = (String(mode.Value) === String(newState.activeMode));
                    btn.className = 'mode-btn' + (isActive ? ' active' : '');
                    
                    if (isActive) {
                        btn.style.setProperty('--mode-color', intToHex(mode.Color));
                        btn.classList.add('pulse');
                    }
                    
                    const iconPath = ICONS[mode.Icon] || ICONS['default'];
                    btn.innerHTML = `<svg viewBox="0 0 24 24">${iconPath}</svg>` + 
                                   (newState.config.showLabels ? `<span>${mode.Caption}</span>` : '');
                    
                    btn.onclick = () => {
                        if (typeof requestAction === 'function') requestAction('SetMode', mode.Value);
                        
                        // Optimistic update
                        const oldActive = state.activeMode;
                        state.activeMode = mode.Value;
                        renderState(state, {...state, activeMode: oldActive});
                    };
                    
                    els.modesContainer.appendChild(btn);
                });
            }
        }

        // Global Symcon Callback – wird von PHP als inline-Script aufgerufen und bei VM_UPDATE
        window.handleMessage = function(payload) {
            try {
                let data = typeof payload === 'string' ? JSON.parse(payload) : payload;
                const oldState = {...state};
                state = {...state, ...data};

                // Config mergen und Defaults sicherstellen
                if (data.config) {
                    state.config = {...(oldState.config || {}), ...data.config};
                }
                if (!state.config) state.config = {gaugeStyle:0, colorCold:3899638, colorWarm:15684676, tempMin:5.0, tempMax:30.0, tempStep:0.5, showLabels:true};
                if (!state.config.tempMax || state.config.tempMax <= state.config.tempMin) state.config.tempMax = (state.config.tempMin || 5) + 25;
                if (!state.config.tempStep || state.config.tempStep <= 0) state.config.tempStep = 0.5;

                renderGaugeStatic();
                renderState(state, oldState);
                updateGaugeValue(state.targetTemp || state.config.tempMin, Object.keys(oldState).length > 0);
            } catch (e) {
                console.error('SCT: handleMessage error', e);
            }
        };

        // Interactions
        function handleDragStart(e) {
            isDragging = true;
            handleDragMove(e);
        }

        function handleDragMove(e) {
            if (!isDragging) return;
            if (e.cancelable) e.preventDefault(); // Prevent scrolling on touch
            
            let angle = getAngleFromEvent(e);
            let val = angleToValue(angle);
            
            // Constrain
            val = Math.max(state.config.tempMin, Math.min(state.config.tempMax, val));
            
            // Optimistic visual update without animation for smooth dragging
            updateGaugeValue(val, false);
            
            // Debounced state update to avoid spamming variables
            state.targetTemp = va        function handleDragEnd(e) {
            if (!isDragging) return;
            isDragging = false;
            if (typeof requestAction === 'function') {
                requestAction('SetTemperature', state.targetTemp);
            }
        }

        // Event-Listener direkt registrieren (Script laeuft synchron nach DOM)
        if (els.svg) {
            els.svg.addEventListener('mousedown', handleDragStart);
            els.svg.addEventListener('touchstart', handleDragStart, {passive: false});
        }
        window.addEventListener('mousemove', handleDragMove);
        window.addEventListener('touchmove', handleDragMove, {passive: false});
        window.addEventListener('mouseup', handleDragEnd);
        window.addEventListener('touchend', handleDragEnd);

        if (els.btnMinus) els.btnMinus.addEventListener('click', () => {
            let newVal = Math.max(state.config.tempMin, (state.targetTemp || state.config.tempMin) - state.config.tempStep);
            newVal = Math.round(newVal / state.config.tempStep) * state.config.tempStep;
            state.targetTemp = newVal;
            updateGaugeValue(newVal);
            if (typeof requestAction === 'function') requestAction('SetTemperature', newVal);
        });

        if (els.btnPlus) els.btnPlus.addEventListener('click', () => {
            let newVal = Math.min(state.config.tempMax, (state.targetTemp || state.config.tempMin) + state.config.tempStep);
            newVal = Math.round(newVal / state.config.tempStep) * state.config.tempStep;
            state.targetTemp = newVal;
            updateGaugeValue(newVal);
            if (typeof requestAction === 'function') requestAction('SetTemperature', newVal);
        });

        // PHP hat Symcon-Callback fuer VM_UPDATE
        window.handleMessage = function(payload) {
            try {
                let data = typeof payload === 'string' ? JSON.parse(payload) : payload;
                const oldState = {...state};
                state = {...state, ...data};
                if (data.config) state.config = {...(oldState.config || {}), ...data.config};
                if (!state.config.tempMax || state.config.tempMax <= state.config.tempMin) state.config.tempMax = (state.config.tempMin || 5) + 25;
                if (!state.config.tempStep) state.config.tempStep = 0.5;
                renderGaugeStatic();
                renderState(state, oldState);
                updateGaugeValue(state.targetTemp || state.config.tempMin, true);
            } catch (e) {
                console.error('SCT: handleMessage error', e);
            }
        };

        // Synchron rendern – direkt beim Script-Aufruf, kein DOMContentLoaded noetig
        renderGaugeStatic();
        renderState(state);
        updateGaugeValue(state.targetTemp || state.config.tempMin, false);
    
