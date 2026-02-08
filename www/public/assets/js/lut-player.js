// public/assets/js/lut-player.js
// Minimal WebGL LUT renderer for an HTMLVideoElement.
// Supports dynamic .cube LUT loading. Uses requestVideoFrameCallback when available.

/* Usage from page:
   const lut = createLUTRenderer({ video: '#zdVideo', canvas: '#lutCanvas' });
   await lut.applyLUT('/luts/Arri_LogC3_to_Rec709_33.cube'); // load/swap anytime
   // lut.clearLUT(); // to bypass LUT
*/

export function createLUTRenderer({ video, canvas }) {
  const vid = typeof video === "string" ? document.querySelector(video) : video;
  const cvs =
    typeof canvas === "string" ? document.querySelector(canvas) : canvas;
  if (!vid || !cvs)
    throw new Error("createLUTRenderer: video/canvas not found");

  // Setup WebGL
  const gl = cvs.getContext("webgl", {
    premultipliedAlpha: false,
    preserveDrawingBuffer: false,
  });
  if (!gl) throw new Error("WebGL not available");

  // Vertex shader: full-screen quad
  const vsSrc = `
    attribute vec2 aPos;
    attribute vec2 aUV;
    varying vec2 vUV;
    void main() {
      vUV = aUV;
      gl_Position = vec4(aPos, 0.0, 1.0);
    }
  `;

  // Fragment shader: sample video texture, then apply 3D LUT packed into a tiled 2D texture.
  // We assume a NxNxN cube packed into (N * N) by N tiles: columns = N, rows = N.
  const fsSrc = `
    precision mediump float;
    varying vec2 vUV;
    uniform sampler2D uVideo;
    uniform sampler2D uLUT;
    uniform float uHasLUT;   // 0.0 -> bypass
    uniform float uSize;     // e.g., 33.0
    // video is assumed in sRGB/Rec709 range [0..1]
    vec3 applyLUT(vec3 color){
      float size = uSize;
      // Avoid edge artifacts
      vec3 c = clamp(color, 0.0, 1.0) * (size - 1.0);
      float blueIndex = floor(c.b + 0.00001);
      float fracB = c.b - blueIndex;

      float tileSize = size;       // N
      float tilesPerRow = size;    // N
      float invSize = 1.0 / size;
      float invTiles = 1.0 / tilesPerRow;

      // Each "slice" for B is a tile at (bx, by) on the big texture
      float by = floor(blueIndex / tilesPerRow);
      float bx = mod(blueIndex, tilesPerRow);

      // Compute UV inside that tile for the R/G position
      // Add 0.5 pixel offsets to sample texels centers
      vec2 base = vec2(bx * tileSize, by * tileSize);
      vec2 uvRG = (base + vec2(c.r + 0.5, c.g + 0.5)) / (tilesPerRow * tileSize);

      // Next slice (blueIndex + 1), clamped
      float blueIndex2 = min(blueIndex + 1.0, size - 1.0);
      float by2 = floor(blueIndex2 / tilesPerRow);
      float bx2 = mod(blueIndex2, tilesPerRow);
      vec2 base2 = vec2(bx2 * tileSize, by2 * tileSize);
      vec2 uvRG2 = (base2 + vec2(c.r + 0.5, c.g + 0.5)) / (tilesPerRow * tileSize);

      vec3 lut1 = texture2D(uLUT, uvRG).rgb;
      vec3 lut2 = texture2D(uLUT, uvRG2).rgb;
      return mix(lut1, lut2, fracB);
    }

    void main(){
      vec3 col = texture2D(uVideo, vUV).rgb;
      if (uHasLUT > 0.5) {
        col = applyLUT(col);
      }
      gl_FragColor = vec4(col, 1.0);
    }
  `;

  function compile(type, src) {
    const sh = gl.createShader(type);
    gl.shaderSource(sh, src);
    gl.compileShader(sh);
    if (!gl.getShaderParameter(sh, gl.COMPILE_STATUS)) {
      throw new Error(gl.getShaderInfoLog(sh) || "Shader compile failed");
    }
    return sh;
  }
  const vs = compile(gl.VERTEX_SHADER, vsSrc);
  const fs = compile(gl.FRAGMENT_SHADER, fsSrc);
  const prog = gl.createProgram();
  gl.attachShader(prog, vs);
  gl.attachShader(prog, fs);
  gl.linkProgram(prog);
  if (!gl.getProgramParameter(prog, gl.LINK_STATUS)) {
    throw new Error(gl.getProgramInfoLog(prog) || "Program link failed");
  }
  gl.useProgram(prog);

  // Full-screen quad
  const quad = gl.createBuffer();
  gl.bindBuffer(gl.ARRAY_BUFFER, quad);
  const verts = new Float32Array([
    //  pos    // uv
    -1, -1, 0, 0, 1, -1, 1, 0, -1, 1, 0, 1, 1, 1, 1, 1,
  ]);
  gl.bufferData(gl.ARRAY_BUFFER, verts, gl.STATIC_DRAW);

  const aPos = gl.getAttribLocation(prog, "aPos");
  const aUV = gl.getAttribLocation(prog, "aUV");
  gl.enableVertexAttribArray(aPos);
  gl.enableVertexAttribArray(aUV);
  gl.vertexAttribPointer(aPos, 2, gl.FLOAT, false, 16, 0);
  gl.vertexAttribPointer(aUV, 2, gl.FLOAT, false, 16, 8);

  // Uniforms
  const uVideo = gl.getUniformLocation(prog, "uVideo");
  const uLUT = gl.getUniformLocation(prog, "uLUT");
  const uHasLUT = gl.getUniformLocation(prog, "uHasLUT");
  const uSize = gl.getUniformLocation(prog, "uSize");

  gl.uniform1i(uVideo, 0);
  gl.uniform1i(uLUT, 1);
  gl.uniform1f(uHasLUT, 0.0);
  gl.uniform1f(uSize, 33.0); // default; will update when LUT loads

  // Textures
  const texVideo = gl.createTexture();
  gl.activeTexture(gl.TEXTURE0);
  gl.bindTexture(gl.TEXTURE_2D, texVideo);
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);

  const texLUT = gl.createTexture();
  gl.activeTexture(gl.TEXTURE1);
  gl.bindTexture(gl.TEXTURE_2D, texLUT);
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);

  // Resize canvas to match CSS pixel size of player
  function resize() {
    const rect = cvs.getBoundingClientRect();
    const w = Math.max(1, Math.floor(rect.width * devicePixelRatio));
    const h = Math.max(1, Math.floor(rect.height * devicePixelRatio));
    if (cvs.width !== w || cvs.height !== h) {
      cvs.width = w;
      cvs.height = h;
      gl.viewport(0, 0, w, h);
    }
  }

  // Upload the current video frame to texture and draw
  function render() {
    resize();

    // Bind video texture and upload current frame
    gl.activeTexture(gl.TEXTURE0);
    gl.bindTexture(gl.TEXTURE_2D, texVideo);

    // NOTE: For cross-origin videos, ensure CORS headers on the proxy file.
    gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, true);
    gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGB, gl.RGB, gl.UNSIGNED_BYTE, vid);

    gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
  }

  // Per-frame loop
  const hasRVFC = "requestVideoFrameCallback" in HTMLVideoElement.prototype;
  let raf = 0;
  function startLoop() {
    if (hasRVFC) {
      const loop = (_now, _meta) => {
        render();
        if (!vid.ended) vid.requestVideoFrameCallback(loop);
      };
      vid.requestVideoFrameCallback(loop);
    } else {
      const tick = () => {
        render();
        raf = requestAnimationFrame(tick);
      };
      cancelAnimationFrame(raf);
      raf = requestAnimationFrame(tick);
    }
  }
  vid.addEventListener("play", startLoop);
  vid.addEventListener("pause", () => {
    if (!hasRVFC) cancelAnimationFrame(raf);
  });
  vid.addEventListener("ended", () => {
    if (!hasRVFC) cancelAnimationFrame(raf);
  });
  // Kick once in case the video is already playing
  if (!vid.paused && !vid.ended) startLoop();

  // --- .cube parser and LUT uploader ---
  async function applyLUT(url) {
    const text = await fetch(url, { cache: "force-cache" }).then((r) =>
      r.text()
    );
    // Parse .cube: look for LUT_3D_SIZE and subsequent RGB triplets
    const lines = text
      .split(/\r?\n/)
      .map((l) => l.trim())
      .filter((l) => l && !l.startsWith("#"));
    let size = 0;
    const data = [];
    for (const line of lines) {
      if (line.startsWith("TITLE")) continue;
      if (line.startsWith("DOMAIN_")) continue;
      if (line.startsWith("LUT_3D_SIZE")) {
        const n = parseInt(line.split(/\s+/)[1], 10);
        if (n > 1 && n <= 65) size = n;
        continue;
      }
      // RGB triplet
      const sp = line.split(/\s+/);
      if (sp.length === 3) {
        data.push(parseFloat(sp[0]), parseFloat(sp[1]), parseFloat(sp[2]));
      }
    }
    if (!size || data.length !== size * size * size * 3) {
      console.warn("LUT parse failed or size mismatch");
      gl.uniform1f(uHasLUT, 0.0);
      return;
    }

    // Pack 3D LUT slices into a 2D tiled texture of (size*size) x size
    const tilesPerRow = size;
    const width = size * tilesPerRow;
    const height = size;
    const pixels = new Uint8Array(width * height * 3);

    // Data order in .cube is usually blue-major: for b in 0..N-1, for g, for r
    // We place each blue slice (N x N) as a tile at (b % N, floor(b/N))
    let idx = 0;
    for (let b = 0; b < size; b++) {
      const tileX = b % tilesPerRow;
      const tileY = Math.floor(b / tilesPerRow); // always 0 because tilesPerRow == size
      for (let g = 0; g < size; g++) {
        for (let r = 0; r < size; r++) {
          const rF = data[idx++],
            gF = data[idx++],
            bF = data[idx++];
          const x = tileX * size + r;
          const y = tileY * size + g;
          const off = (y * width + x) * 3;
          pixels[off + 0] = Math.max(0, Math.min(255, Math.round(rF * 255)));
          pixels[off + 1] = Math.max(0, Math.min(255, Math.round(gF * 255)));
          pixels[off + 2] = Math.max(0, Math.min(255, Math.round(bF * 255)));
        }
      }
    }

    gl.activeTexture(gl.TEXTURE1);
    gl.bindTexture(gl.TEXTURE_2D, texLUT);
    gl.texImage2D(
      gl.TEXTURE_2D,
      0,
      gl.RGB,
      width,
      height,
      0,
      gl.RGB,
      gl.UNSIGNED_BYTE,
      pixels
    );
    gl.uniform1f(uSize, size);
    gl.uniform1f(uHasLUT, 1.0);
  }

  function clearLUT() {
    gl.uniform1f(uHasLUT, 0.0);
  }

  // Public API
  return { applyLUT, clearLUT, gl };
}
