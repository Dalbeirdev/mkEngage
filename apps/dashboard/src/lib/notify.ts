/**
 * Agent alerts (Phase 30): a short two-tone ping via WebAudio (no asset
 * download) + a title-bar badge until the tab regains focus.
 */

let audioContext: AudioContext | null = null;

export function playPing(): void {
  try {
    audioContext ??= new AudioContext();
    const now = audioContext.currentTime;

    for (const [offset, frequency] of [
      [0, 880],
      [0.14, 1175],
    ] as const) {
      const oscillator = audioContext.createOscillator();
      const gain = audioContext.createGain();
      oscillator.type = "sine";
      oscillator.frequency.value = frequency;
      gain.gain.setValueAtTime(0.001, now + offset);
      gain.gain.exponentialRampToValueAtTime(0.08, now + offset + 0.02);
      gain.gain.exponentialRampToValueAtTime(0.001, now + offset + 0.12);
      oscillator.connect(gain).connect(audioContext.destination);
      oscillator.start(now + offset);
      oscillator.stop(now + offset + 0.14);
    }
  } catch {
    // Autoplay policy before first interaction, or no audio device: silent.
  }
}

let baseTitle: string | null = null;
let listenerArmed = false;

export function flashTitle(prefix: string): void {
  if (typeof document === "undefined") return;
  baseTitle ??= document.title;
  document.title = `${prefix} ${baseTitle}`;

  if (!listenerArmed) {
    listenerArmed = true;
    const restore = () => {
      if (baseTitle !== null && document.hasFocus()) document.title = baseTitle;
    };
    window.addEventListener("focus", restore);
    document.addEventListener("visibilitychange", restore);
  }
}
