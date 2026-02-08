import { createLUTRenderer } from "/assets/js/lut-player.js";

const lut = createLUTRenderer({
  video: "#zdVideo",
  canvas: "#lutCanvas",
});

// Example: apply a LUT at runtime
// lut.applyLUT('/luts/Arri_LogC3_to_Rec709_33.cube');

// Example: clear LUT
// lut.clearLUT();

// OPTIONAL: simple UI bindings if you add a select
// document.getElementById('lutSelect').addEventListener('change', e => {
//   if (!e.target.value) return lut.clearLUT();
//   lut.applyLUT(e.target.value);
// });
