import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { useColorScheme } from 'react-native';

import { mmkv } from '@store/mmkv';
import { buildTheme, type ColorScheme, type Theme } from './index';

/**
 * ThemeProvider — M0 baseline + M6 user-override.
 *
 * Tier 1 dulu cuma follow `useColorScheme()` (system). Sprint M6 menambah
 * override pengguna (light/dark/system) yang persist di MMKV.
 *
 * Pemakaian:
 *   const { theme, mode, setMode } = useThemeControl();
 *   setMode('dark');     // explicit dark
 *   setMode('system');   // ikut OS
 *
 * Komponen biasa cukup pakai `useTheme()` seperti sebelumnya — tidak
 * butuh refactor. Tambahan API hanya `useThemeControl()` untuk halaman
 * Preferences.
 */

export type ThemeMode = 'system' | 'light' | 'dark';

const KEY_MODE = 'theme.mode';

const isMode = (v: unknown): v is ThemeMode =>
  v === 'system' || v === 'light' || v === 'dark';

const readStoredMode = (): ThemeMode => {
  const raw = mmkv.getString(KEY_MODE);
  return isMode(raw) ? raw : 'system';
};

interface ThemeContextValue {
  theme: Theme;
  mode: ThemeMode;
  /** Resolved scheme yang aktif sekarang (`light` atau `dark`). */
  scheme: ColorScheme;
  setMode: (mode: ThemeMode) => void;
}

const defaultTheme = buildTheme('light');
const ThemeContext = createContext<ThemeContextValue>({
  theme: defaultTheme,
  mode: 'system',
  scheme: 'light',
  setMode: () => undefined,
});

interface Props { children: React.ReactNode }

export const ThemeProvider: React.FC<Props> = ({ children }) => {
  const systemScheme = useColorScheme();
  const [mode, setModeState] = useState<ThemeMode>(() => readStoredMode());

  // Sync state ke MMKV setiap kali user pilih mode baru.
  const setMode = useCallback((next: ThemeMode) => {
    if (!isMode(next)) return;
    if (next === 'system') {
      mmkv.delete(KEY_MODE);
    } else {
      mmkv.set(KEY_MODE, next);
    }
    setModeState(next);
  }, []);

  // Untuk skenario test atau hot-reload: re-read storage saat mount.
  useEffect(() => {
    setModeState(readStoredMode());
  }, []);

  const resolvedScheme: ColorScheme = useMemo(() => {
    if (mode === 'light') return 'light';
    if (mode === 'dark') return 'dark';
    return systemScheme === 'dark' ? 'dark' : 'light';
  }, [mode, systemScheme]);

  const theme = useMemo(() => buildTheme(resolvedScheme), [resolvedScheme]);

  const value = useMemo<ThemeContextValue>(
    () => ({ theme, mode, scheme: resolvedScheme, setMode }),
    [theme, mode, resolvedScheme, setMode],
  );

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
};

/** Hook lama — kembalikan theme aja (back-compat). */
export const useTheme = (): Theme => useContext(ThemeContext).theme;

/** Hook baru untuk halaman Preferences yang butuh kontrol mode. */
export const useThemeControl = (): ThemeContextValue => useContext(ThemeContext);