/**
 * Detector de calidad de red.
 *
 * Considera "buena conexión estable" cuando:
 *  - Tipo: wifi siempre OK; cellular solo si effectiveType === '4g'.
 *  - downlink ≥ 1.5 Mbps.
 *  - rtt ≤ 300 ms.
 *  - 3 mediciones consecutivas con resultados similares (estabilidad).
 *
 * window.vidalsaNet.isGoodAndStable() devuelve boolean.
 * window.vidalsaNet.startMonitoring(callback) inicia checks periódicos.
 */

(function () {
    'use strict';

    const SAMPLES_REQUIRED = 3;
    const SAMPLE_INTERVAL_MS = 10_000;
    const MIN_DOWNLINK_MBPS = 1.5;
    const MAX_RTT_MS = 300;

    let _samples = []; // últimas mediciones
    let _interval = null;

    function takeSample() {
        const c = navigator.connection;
        if (!c) return null; // API no soportada
        return {
            type: c.type || 'unknown',
            effectiveType: c.effectiveType || '4g',
            downlink: c.downlink || 0,
            rtt: c.rtt || 0,
            saveData: !!c.saveData,
            ts: Date.now(),
        };
    }

    function sampleIsGood(s) {
        if (!s) return false;
        if (s.saveData) return false;                       // usuario en modo ahorro
        if (s.type === 'wifi' || s.type === 'ethernet') return true;
        if (s.effectiveType !== '4g') return false;          // 3g/2g no son buenas
        if (s.downlink < MIN_DOWNLINK_MBPS) return false;
        if (s.rtt > MAX_RTT_MS) return false;
        return true;
    }

    function samplesAreStable(samples) {
        if (samples.length < SAMPLES_REQUIRED) return false;
        const lastN = samples.slice(-SAMPLES_REQUIRED);
        if (!lastN.every(sampleIsGood)) return false;
        // Variación de downlink < 30%
        const dls = lastN.map(s => s.downlink).filter(v => v > 0);
        if (dls.length < SAMPLES_REQUIRED) return false;
        const min = Math.min(...dls), max = Math.max(...dls);
        if ((max - min) / min > 0.3) return false;
        return true;
    }

    const vidalsaNet = {
        /** Estado actual: ¿buena y estable? */
        isGoodAndStable() {
            return samplesAreStable(_samples);
        },

        /** Tomar muestras cada 10s y disparar callback cuando esté good+stable. */
        startMonitoring(onGood) {
            if (_interval) return;
            _samples = [];
            const tick = () => {
                const s = takeSample();
                if (s) _samples.push(s);
                if (_samples.length > SAMPLES_REQUIRED * 2) _samples.shift();
                if (this.isGoodAndStable() && typeof onGood === 'function') {
                    onGood(_samples[_samples.length - 1]);
                }
            };
            tick(); // primer chequeo inmediato
            _interval = setInterval(tick, SAMPLE_INTERVAL_MS);
        },

        stopMonitoring() {
            if (_interval) { clearInterval(_interval); _interval = null; }
            _samples = [];
        },

        currentSample() { return _samples.length ? _samples[_samples.length - 1] : null; },
    };

    window.vidalsaNet = vidalsaNet;
})();
