import React from 'react';
import { StyleSheet, TextInput, type TextInputProps } from 'react-native';

import { useTheme } from '@theme/ThemeProvider';

interface Props extends Omit<TextInputProps, 'value' | 'onChangeText' | 'onChange'> {
  value: string;
  onChange: (next: string) => void;
}

/**
 * DateInput — shared component untuk input tanggal YYYY-MM-DD.
 *
 * MVP pakai TextInput biasa dengan validasi inline ringan. Saat polish
 * sprint ready, replace internal pakai `react-native-date-picker` atau
 * `@react-native-community/datetimepicker` tanpa ubah call site (props
 * sama: value + onChange).
 *
 * Auto-format saat user ketik:
 *   "20260125" → "2026-01-25"
 * Sehingga user yang males tap dash bisa langsung 8 digit.
 */
export const DateInput: React.FC<Props> = ({ value, onChange, ...rest }) => {
  const theme = useTheme();
  const c = theme.colors;

  const handleChange = (raw: string) => {
    // Strip non-digit, batasi 8 char (YYYYMMDD)
    const digits = raw.replace(/\D/g, '').slice(0, 8);
    let formatted = digits;
    if (digits.length > 4) formatted = digits.slice(0, 4) + '-' + digits.slice(4);
    if (digits.length > 6) formatted = digits.slice(0, 4) + '-' + digits.slice(4, 6) + '-' + digits.slice(6);
    onChange(formatted);
  };

  return (
    <TextInput
      {...rest}
      value={value}
      onChangeText={handleChange}
      keyboardType="number-pad"
      maxLength={10}
      placeholder={rest.placeholder ?? 'YYYY-MM-DD'}
      placeholderTextColor={c.textMuted}
      style={[
        styles.input,
        { color: c.text, borderColor: c.border, backgroundColor: c.surfaceAlt },
        rest.style,
      ]}
    />
  );
};

const styles = StyleSheet.create({
  input: {
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 8,
    paddingHorizontal: 12,
    paddingVertical: 10,
    fontSize: 15,
  },
});