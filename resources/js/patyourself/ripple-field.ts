/* ============================================================
   patyourself — "Ripples from one small act"
   A calm field of points. One quiet origin dot sends rings
   rippling outward — each ring a loop, expanding endlessly,
   never quite closing into a perfect circle. Progress, not
   perfection.

   Ported from the design-system handoff (landing/ripple-field.js).
   Reads the global `THREE` (loaded from CDN by the landing page) so
   the app bundle gains no new dependency. Call initRippleField(canvas)
   once the global is present.

   Interactions:
     • click/tap the field  -> drop a new ripple there
     • scroll               -> pushes ripples forward
     • cursor               -> gently tilts the pond
     • ambient              -> the origin breathes its own rings

   Emits 'py-pat' { x, y, pats } (screen px) and 'py-pats-reset'.
   ============================================================ */

export type RipplePalette = 'mono' | 'accents';

export type RippleApi = {
    pat: (fx?: number, fz?: number) => void;
    setPalette: (p: RipplePalette) => void;
    setTheme: (darkMode: boolean) => void;
    setPace: (p: number) => void;
    resetPats: () => void;
    getPats: () => number;
    dispose: () => void;
};

const STORAGE_KEY = 'py_landing_pats_v1';
const MAX_DROPS = 14;

/* field extent + resolution */
const FIELD = 15.0; /* half-width in world units */
const GRID = 156; /* points per side (~24k pts) */

type PaletteSet = {
    low: number[];
    mid: number[];
    peak: number[];
    origin: number[];
    multi?: boolean;
};

const PALETTES: Record<RipplePalette, PaletteSet> = {
    mono: {
        /* clay-coral on cream, sage origin */
        low: [0xe9, 0xdd, 0xc8],
        mid: [0xe2, 0x6b, 0x3e],
        peak: [0xf3, 0xa9, 0x82],
        origin: [0x5e, 0x8c, 0x6a],
    },
    accents: {
        /* the four loop-stage hues drift across the rings */
        low: [0xe6, 0xdc, 0xc8],
        mid: [0x4f, 0x8a, 0x8b],
        peak: [0x9a, 0x6f, 0x8c],
        origin: [0xe2, 0x6b, 0x3e],
        multi: true,
    },
};
const PALETTES_DARK: Record<RipplePalette, PaletteSet> = {
    mono: {
        low: [0x3a, 0x30, 0x27],
        mid: [0xee, 0x7e, 0x50],
        peak: [0xff, 0xb5, 0x8f],
        origin: [0x7c, 0xae, 0x89],
    },
    accents: {
        low: [0x38, 0x30, 0x28],
        mid: [0x5f, 0xa6, 0xa7],
        peak: [0xc1, 0x92, 0xb0],
        origin: [0xe2, 0x6b, 0x3e],
        multi: true,
    },
};

const VERT = [
    'uniform float uTime;',
    'uniform float uPixelRatio;',
    'uniform float uWaveSpeed;',
    'uniform float uAmbient;',
    'uniform vec4  uDrops[' +
        MAX_DROPS +
        '];' /* xy = pos, z = birth, w = amp */,
    'attribute float aRand;',
    'varying float vInt;',
    'varying float vEdge;',
    'varying float vRand;',
    'varying float vRad;',
    'void main() {',
    '  vec3 pos = position;',
    '  float dC = length(pos.xz);',
    '  float disp = 0.0;',
    '  float intensity = 0.0;',
    /* ambient rings breathing out from the origin */
    '  float ambPhase = dC * 0.85 - uTime * 1.25;',
    '  disp += sin(ambPhase) * 0.04 * uAmbient * exp(-dC * 0.07);',
    '  intensity += (0.5 + 0.5 * sin(ambPhase)) * 0.09 * uAmbient * exp(-dC * 0.07);',
    /* the dropped ripples */
    '  for (int i = 0; i < ' + MAX_DROPS + '; i++) {',
    '    vec4 d = uDrops[i];',
    '    if (d.w <= 0.0) continue;',
    '    float age = uTime - d.z;',
    '    if (age < 0.0) continue;',
    '    vec2 off = pos.xz - d.xy;',
    '    float dist = length(off);',
    '    float ang = atan(off.y, off.x);',
    '    float wob = sin(ang * 3.0 + aRand * 6.2831) * 0.06;',
    '    float front = age * uWaveSpeed;',
    '    float wsum = 0.0;',
    '    for (int j = 0; j < 3; j++) {',
    '      float r0 = front - float(j) * 1.5;',
    '      if (r0 < 0.0) continue;',
    '      float ring = dist - r0 + wob;',
    '      wsum += exp(-ring * ring / 0.32) * (1.0 - float(j) * 0.3);',
    '    }',
    '    float decay = exp(-age * 0.4) * exp(-dist * 0.04);',
    '    float a = wsum * decay * d.w;',
    '    disp += a * 0.5;',
    '    intensity += a;',
    '  }',
    '  pos.y = disp;',
    '  vInt = intensity;',
    '  vRand = aRand;',
    '  vRad = dC;',
    '  vEdge = smoothstep(' +
        FIELD.toFixed(1) +
        ', ' +
        (FIELD * 0.55).toFixed(2) +
        ', dC);',
    '  vec4 mv = modelViewMatrix * vec4(pos, 1.0);',
    '  gl_Position = projectionMatrix * mv;',
    '  float size = 1.7 + intensity * 7.0;',
    '  gl_PointSize = size * uPixelRatio * (150.0 / -mv.z);',
    '}',
].join('\n');

const FRAG = [
    'precision highp float;',
    'uniform vec3 uLow;',
    'uniform vec3 uMid;',
    'uniform vec3 uPeak;',
    'uniform float uMulti;',
    'uniform float uTime;',
    'varying float vInt;',
    'varying float vEdge;',
    'varying float vRand;',
    'varying float vRad;',
    'void main() {',
    '  vec2 c = gl_PointCoord - 0.5;',
    '  float d2 = dot(c, c);',
    '  if (d2 > 0.25) discard;',
    '  float disc = smoothstep(0.25, 0.04, d2);',
    '  float t = clamp(vInt, 0.0, 1.4);',
    '  vec3 col = mix(uLow, uMid, smoothstep(0.0, 0.45, t));',
    '  col = mix(col, uPeak, smoothstep(0.55, 1.15, t));',
    /* accents palette: let hue drift along the radius for the four-stage feel */
    '  if (uMulti > 0.5) {',
    '    float h = fract(vRad * 0.045 - uTime * 0.015);',
    '    vec3 a = vec3(0.31, 0.54, 0.55);' /* teal */,
    '    vec3 b = vec3(0.60, 0.44, 0.55);' /* plum */,
    '    vec3 cc = vec3(0.89, 0.42, 0.24);' /* clay */,
    '    vec3 e = vec3(0.48, 0.66, 0.55);' /* sage */,
    '    vec3 hue = mix(mix(a, b, smoothstep(0.0,0.33,h)), mix(cc, e, smoothstep(0.66,1.0,h)), smoothstep(0.33,0.66,h));',
    '    col = mix(col, hue, smoothstep(0.2, 0.9, t) * 0.8);',
    '  }',
    '  float alpha = disc * vEdge * (0.07 + 0.93 * smoothstep(0.0, 0.4, vInt + 0.05));',
    '  gl_FragColor = vec4(col, alpha);',
    '}',
].join('\n');

function loadPats(): number {
    try {
        return parseInt(localStorage.getItem(STORAGE_KEY) || '0', 10) || 0;
    } catch {
        return 0;
    }
}
function savePats(n: number): void {
    try {
        localStorage.setItem(STORAGE_KEY, String(n));
    } catch {
        /* ignore */
    }
}

/**
 * Boot the ripple scene onto `canvas`. Requires the global `THREE` (the
 * landing page loads it from CDN first). Returns the imperative api the React
 * layer drives; call `dispose()` on unmount.
 */
export function initRippleField(canvas: HTMLCanvasElement): RippleApi {
    const THREE: any = (window as any).THREE;

    if (!THREE) {
        throw new Error('THREE is not loaded');
    }

    const v3 = (arr: number[]) =>
        new THREE.Vector3(arr[0] / 255, arr[1] / 255, arr[2] / 255);

    const renderer = new THREE.WebGLRenderer({
        canvas: canvas,
        alpha: true,
        antialias: true,
    });
    const DPR = Math.min(window.devicePixelRatio || 1, 2);
    renderer.setPixelRatio(DPR);

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(38, 1, 0.1, 100);

    const field = new THREE.Group();
    scene.add(field);

    /* ---------- point geometry ---------- */
    const count = GRID * GRID;
    const positions = new Float32Array(count * 3);
    const rands = new Float32Array(count);
    let k = 0;

    for (let iy = 0; iy < GRID; iy++) {
        for (let ix = 0; ix < GRID; ix++) {
            let x = (ix / (GRID - 1) - 0.5) * 2 * FIELD;
            let z = (iy / (GRID - 1) - 0.5) * 2 * FIELD;
            const jit = ((2 * FIELD) / (GRID - 1)) * 0.5;
            x += (Math.random() - 0.5) * jit;
            z += (Math.random() - 0.5) * jit;
            positions[k * 3] = x;
            positions[k * 3 + 1] = 0;
            positions[k * 3 + 2] = z;
            rands[k] = Math.random();
            k++;
        }
    }

    const geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    geo.setAttribute('aRand', new THREE.BufferAttribute(rands, 1));

    const dropArr: any[] = [];

    for (let i = 0; i < MAX_DROPS; i++) {
        dropArr.push(new THREE.Vector4(0, 0, 0, 0));
    }

    const uniforms = {
        uTime: { value: 0 },
        uPixelRatio: { value: DPR },
        uWaveSpeed: { value: 2.4 },
        uAmbient: { value: 1.0 },
        uDrops: { value: dropArr },
        uLow: { value: v3(PALETTES.mono.low) },
        uMid: { value: v3(PALETTES.mono.mid) },
        uPeak: { value: v3(PALETTES.mono.peak) },
        uMulti: { value: 0 },
    };

    const pointsMat = new THREE.ShaderMaterial({
        uniforms: uniforms,
        vertexShader: VERT,
        fragmentShader: FRAG,
        transparent: true,
        depthWrite: false,
        depthTest: false,
    });
    const points = new THREE.Points(geo, pointsMat);
    field.add(points);

    /* ---------- the origin: one small act ---------- */
    const originMat = new THREE.MeshBasicMaterial({ transparent: true });
    const origin = new THREE.Mesh(
        new THREE.CircleGeometry(0.34, 40),
        originMat,
    );
    origin.rotation.x = -Math.PI / 2;
    origin.position.y = 0.02;
    field.add(origin);
    const halo = new THREE.Mesh(
        new THREE.RingGeometry(0.42, 0.56, 48),
        new THREE.MeshBasicMaterial({ transparent: true, opacity: 0.4 }),
    );
    halo.rotation.x = -Math.PI / 2;
    halo.position.y = 0.015;
    field.add(halo);

    /* ---------- state ---------- */
    const state = {
        pace: 1,
        palette: 'mono' as RipplePalette,
        theme: 'light' as 'light' | 'dark',
        pats: loadPats(),
        pointer: { x: 0, y: 0, tx: 0, ty: 0 },
        reduced: false,
        scrollPhase: 0,
        lastScroll: 0,
        nextDrop: 0,
        disposed: false,
    };

    try {
        state.reduced = window.matchMedia(
            '(prefers-reduced-motion: reduce)',
        ).matches;
    } catch {
        /* ignore */
    }

    function applyPalette() {
        const set = (state.theme === 'dark' ? PALETTES_DARK : PALETTES)[
            state.palette
        ];
        uniforms.uLow.value.copy(v3(set.low));
        uniforms.uMid.value.copy(v3(set.mid));
        uniforms.uPeak.value.copy(v3(set.peak));
        uniforms.uMulti.value = set.multi ? 1 : 0;
        const o = v3(set.origin);
        originMat.color.setRGB(o.x, o.y, o.z);
        halo.material.color.setRGB(o.x, o.y, o.z);
    }
    applyPalette();

    /* ---------- drops ---------- */
    let dropHead = 0;
    const clock = new THREE.Clock();

    function addDrop(fx: number, fz: number, amp: number) {
        const slot = dropArr[dropHead % MAX_DROPS];
        slot.set(fx, fz, clock.elapsedTime, amp);
        dropHead++;
    }

    function fieldToScreen(fx: number, fz: number) {
        const v = new THREE.Vector3(fx, 0.3, fz).applyMatrix4(
            field.matrixWorld,
        );
        v.project(camera);
        const r = canvas.getBoundingClientRect();

        return {
            x: r.left + (v.x * 0.5 + 0.5) * r.width,
            y: r.top + (-v.y * 0.5 + 0.5) * r.height,
        };
    }

    function pat(fx?: number, fz?: number, silent?: boolean) {
        let px = fx;
        let pz = fz;

        if (typeof px !== 'number') {
            px = 0;
            pz = 0;
        }

        addDrop(px, pz as number, 1.0);

        if (!silent) {
            state.pats += 1;
            savePats(state.pats);
            const p = fieldToScreen(px, pz as number);
            window.dispatchEvent(
                new CustomEvent('py-pat', {
                    detail: { x: p.x, y: p.y, pats: state.pats },
                }),
            );
        }
    }

    /* ---------- interaction ---------- */
    const ray = new THREE.Raycaster();
    const ndc = new THREE.Vector2();
    const plane = new THREE.Plane(new THREE.Vector3(0, 1, 0), 0);
    const hitPt = new THREE.Vector3();

    function pointerField(ev: PointerEvent) {
        const r = canvas.getBoundingClientRect();
        ndc.x = ((ev.clientX - r.left) / r.width) * 2 - 1;
        ndc.y = -((ev.clientY - r.top) / r.height) * 2 + 1;
        ray.setFromCamera(ndc, camera);
        const ok = ray.ray.intersectPlane(plane, hitPt);

        if (!ok) {
            return null;
        }

        /* into field-local space */
        const local = field.worldToLocal(hitPt.clone());

        return { x: local.x, z: local.z };
    }

    function onMove(ev: PointerEvent) {
        const r = canvas.getBoundingClientRect();
        state.pointer.tx = ((ev.clientX - r.left) / r.width) * 2 - 1;
        state.pointer.ty = -((ev.clientY - r.top) / r.height) * 2 + 1;
    }
    function onDown(ev: PointerEvent) {
        const f = pointerField(ev);

        if (!f) {
            return;
        }

        if (Math.abs(f.x) <= FIELD && Math.abs(f.z) <= FIELD) {
            pat(f.x, f.z);
        }
    }
    canvas.addEventListener('pointermove', onMove);
    canvas.addEventListener('pointerdown', onDown);

    /* scroll pushes ripples outward */
    function onScroll() {
        const y = window.scrollY || window.pageYOffset || 0;
        const dy = y - state.lastScroll;
        state.lastScroll = y;
        state.scrollPhase += Math.abs(dy) * 0.0016;

        if (state.scrollPhase > 1) {
            state.scrollPhase = 0;
            const ang = Math.random() * Math.PI * 2;
            const rad = 1.0 + Math.random() * 2.2;
            addDrop(Math.cos(ang) * rad, Math.sin(ang) * rad, 0.7);
        }
    }
    window.addEventListener('scroll', onScroll, { passive: true });

    /* ---------- resize ---------- */
    function resize() {
        const w = canvas.clientWidth,
            h = canvas.clientHeight;

        if (!w || !h) {
            return;
        }

        renderer.setSize(w, h, false);
        camera.aspect = w / h;
        const wide = w / h > 1.15;

        if (wide) {
            camera.position.set(0, 8.4, 13.5);
            camera.lookAt(0, -1.6, -1.5);
            field.position.set(2.4, 0, 0);
        } else {
            camera.position.set(0, 9.5, 12.5);
            camera.lookAt(0, -1.2, -1);
            field.position.set(0, -1.2, -2);
        }

        camera.updateProjectionMatrix();
    }
    window.addEventListener('resize', resize);
    resize();

    /* seed a gentle ring so it's alive on arrival */
    addDrop(0, 0, 0.9);

    /* ---------- animation ---------- */
    function tick() {
        if (state.disposed) {
            return;
        }

        requestAnimationFrame(tick);
        const dt = Math.min(clock.getDelta(), 0.05);
        const pace = state.pace * (state.reduced ? 0.4 : 1);
        uniforms.uTime.value += dt * pace;
        uniforms.uWaveSpeed.value = 2.4 * pace;
        const t = uniforms.uTime.value;

        /* ambient origin rings, paced */
        state.nextDrop -= dt * pace;

        if (state.nextDrop <= 0) {
            addDrop(
                (Math.random() - 0.5) * 0.5,
                (Math.random() - 0.5) * 0.5,
                0.55,
            );
            state.nextDrop = 3.4 + Math.random() * 2.2;
        }

        /* origin breathes */
        const pulse = state.reduced ? 1 : 1 + Math.sin(t * 1.25) * 0.12;
        origin.scale.setScalar(pulse);
        originMat.opacity = 0.85;
        const hs = state.reduced
            ? 1
            : 1 + (Math.sin(t * 1.25 - 0.6) * 0.5 + 0.5) * 0.6;
        halo.scale.setScalar(hs);
        halo.material.opacity = 0.42 * (1 - ((hs - 1) / 0.6) * 0.7);

        /* gentle cursor tilt of the whole pond */
        state.pointer.x +=
            (state.pointer.tx - state.pointer.x) * Math.min(dt * 3, 1);
        state.pointer.y +=
            (state.pointer.ty - state.pointer.y) * Math.min(dt * 3, 1);
        field.rotation.z = state.pointer.x * 0.05;
        field.rotation.x = -state.pointer.y * 0.04;

        renderer.render(scene, camera);
    }
    tick();

    /* ---------- public api ---------- */
    return {
        pat: (fx?: number, fz?: number) => {
            pat(fx, fz);
        },
        setPalette: (p: RipplePalette) => {
            state.palette = p === 'accents' ? 'accents' : 'mono';
            applyPalette();
        },
        setTheme: (darkMode: boolean) => {
            state.theme = darkMode ? 'dark' : 'light';
            applyPalette();
        },
        setPace: (p: number) => {
            state.pace = p;
        },
        resetPats: () => {
            state.pats = 0;
            savePats(0);
            window.dispatchEvent(
                new CustomEvent('py-pats-reset', { detail: { pats: 0 } }),
            );
        },
        getPats: () => state.pats,
        dispose: () => {
            state.disposed = true;
            window.removeEventListener('resize', resize);
            window.removeEventListener('scroll', onScroll);
            canvas.removeEventListener('pointermove', onMove);
            canvas.removeEventListener('pointerdown', onDown);
            geo.dispose();
            pointsMat.dispose();
            renderer.dispose();
        },
    };
}

let threePromise: Promise<void> | null = null;

/**
 * Inject the Three.js UMD build from CDN once (matching the source design,
 * which keeps Three out of the app bundle). Resolves when `window.THREE` is
 * ready; rejects if the script fails to load.
 */
export function loadThree(
    src = 'https://unpkg.com/three@0.149.0/build/three.min.js',
): Promise<void> {
    if ((window as any).THREE) {
        return Promise.resolve();
    }

    if (threePromise) {
        return threePromise;
    }

    threePromise = new Promise<void>((resolve, reject) => {
        const existing = document.querySelector<HTMLScriptElement>(
            'script[data-three-cdn]',
        );

        if (existing) {
            existing.addEventListener('load', () => resolve());
            existing.addEventListener('error', () =>
                reject(new Error('Failed to load three.js')),
            );

            return;
        }

        const script = document.createElement('script');
        script.src = src;
        script.async = true;
        script.crossOrigin = 'anonymous';
        script.dataset.threeCdn = 'true';
        script.addEventListener('load', () => resolve());
        script.addEventListener('error', () => {
            threePromise = null;
            reject(new Error('Failed to load three.js'));
        });
        document.head.appendChild(script);
    });

    return threePromise;
}
