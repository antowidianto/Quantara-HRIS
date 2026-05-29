/**
 * Theme & design tokens — HRIS Mobile
 *
 * Light & dark mode variants. Drive lewat `useColorScheme()` di App root.
 * Token Indonesia-friendly:
 *   - Brand primary biru korporat (mirip web frontend)
 *   - Aksen hijau utk success (selesai clock-in, approve)
 *   - Merah merah-bata utk error (no signal, GPS off)
 */

export type ColorScheme = 'light' | 'dark';

const palette = {
  primary50:  '#E8F1FF',
  primary100: '#CFE0FF',
  primary500: '#1E63D6',
  primary600: '#1853B5',
  primary700: '#123E8C',

  green500: '#1FA85F',
  red500:   '#D9534F',
  orange500:'#E5A100',

  gray50:  '#FAFBFC',
  gray100: '#F4F5F7',
  gray200: '#E1E4E8',
  gray400: '#A0A6AD',
  gray600: '#5C636C',
  gray800: '#262A30',
  gray900: '#16191D',
};

export interface ThemeColors {
  bg: string;
  surface: string;
  surfaceAlt: string;
  border: string;
  text: string;
  textMuted: string;
  primary: string;
  primaryText: string;
  success: string;
  warning: string;
  danger: string;
}

export interface Theme {
  scheme: ColorScheme;
  colors: ThemeColors;
  spacing: (n: number) => number;
  radius: { sm: number; md: number; lg: number; pill: number };
  typography: {
    h1: { fontSize: number; fontWeight: '600' | '700' };
    h2: { fontSize: number; fontWeight: '600' | '700' };
    body: { fontSize: number; fontWeight: '400' };
    caption: { fontSize: number; fontWeight: '400' };
  };
}

const lightColors: ThemeColors = {
  bg:         palette.gray50,
  surface:    '#FFFFFF',
  surfaceAlt: palette.gray100,
  border:     palette.gray200,
  text:       palette.gray900,
  textMuted:  palette.gray600,
  primary:    palette.primary500,
  primaryText:'#FFFFFF',
  success:    palette.green500,
  warning:    palette.orange500,
  danger:     palette.red500,
};

const darkColors: ThemeColors = {
  bg:         palette.gray900,
  surface:    palette.gray800,
  surfaceAlt: '#1E232A',
  border:     '#2C313A',
  text:       '#F4F5F7',
  textMuted:  palette.gray400,
  primary:    palette.primary500,
  primaryText:'#FFFFFF',
  success:    palette.green500,
  warning:    palette.orange500,
  danger:     palette.red500,
};

const baseUnit = 4;

export const buildTheme = (scheme: ColorScheme): Theme => ({
  scheme,
  colors: scheme === 'dark' ? darkColors : lightColors,
  spacing: (n: number) => n * baseUnit,
  radius: { sm: 4, md: 8, lg: 12, pill: 999 },
  typography: {
    h1:      { fontSize: 24, fontWeight: '700' },
    h2:      { fontSize: 18, fontWeight: '600' },
    body:    { fontSize: 14, fontWeight: '400' },
    caption: { fontSize: 12, fontWeight: '400' },
  },
});