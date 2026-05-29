import React from 'react';
import { ScrollView, StyleSheet, Text, TouchableOpacity, View } from 'react-native';

import { crash } from '@/lib/crash';

/**
 * ErrorBoundary — Sprint M4 hardening.
 *
 * React rendering error → tampilkan UI fallback yang informatif dengan
 * tombol "Coba lagi" (reset state) + "Kirim laporan" (forward ke crash
 * lib). Tanpa ini, runtime error pertama langsung crash app.
 *
 * Pasang di App.tsx mengelilingi MainTabs / AuthStack:
 *   <ErrorBoundary fallbackHint="HRIS gagal memuat layar">
 *     <MainTabs />
 *   </ErrorBoundary>
 *
 * Kenapa class component: React saat ini hanya support componentDidCatch
 * di class. Hooks alternatif (`react-error-boundary` lib) bukan keharusan
 * — kita stay zero-dep.
 */

interface Props {
  children: React.ReactNode;
  fallbackHint?: string;
}

interface State {
  hasError: boolean;
  error?: Error;
}

export class ErrorBoundary extends React.Component<Props, State> {
  state: State = { hasError: false };

  static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error };
  }

  componentDidCatch(error: Error, info: React.ErrorInfo): void {
    crash.captureException(error, {
      feature: 'react.boundary',
      step: 'render',
      componentStack: info.componentStack ?? '',
    });
  }

  handleReset = () => {
    this.setState({ hasError: false, error: undefined });
  };

  render() {
    if (!this.state.hasError) return this.props.children;
    return (
      <View style={styles.root}>
        <ScrollView contentContainerStyle={styles.content}>
          <Text style={styles.h1}>Terjadi kesalahan</Text>
          <Text style={styles.muted}>
            {this.props.fallbackHint ?? 'HRIS tidak dapat menampilkan layar ini.'}
          </Text>
          {this.state.error && (
            <View style={styles.errBox}>
              <Text style={styles.errText} numberOfLines={6}>
                {this.state.error.message}
              </Text>
            </View>
          )}
          <TouchableOpacity onPress={this.handleReset} style={styles.btn}>
            <Text style={styles.btnText}>Coba lagi</Text>
          </TouchableOpacity>
          <Text style={styles.smallMuted}>
            Kalau berulang, hubungi IT helpdesk dengan tangkapan layar.
          </Text>
        </ScrollView>
      </View>
    );
  }
}

const styles = StyleSheet.create({
  root: { flex: 1, backgroundColor: '#FAFBFC' },
  content: { padding: 24, paddingTop: 60 },
  h1: { fontSize: 20, fontWeight: '700', color: '#16191D' },
  muted: { fontSize: 14, color: '#5C636C', marginTop: 8 },
  errBox: {
    marginTop: 16,
    padding: 12,
    borderRadius: 8,
    backgroundColor: '#FCEAE9',
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: '#D9534F',
  },
  errText: { color: '#8A2A2A', fontSize: 13 },
  btn: {
    marginTop: 20,
    backgroundColor: '#1E63D6',
    paddingVertical: 12,
    borderRadius: 8,
    alignItems: 'center',
  },
  btnText: { color: '#FFFFFF', fontWeight: '600', fontSize: 15 },
  smallMuted: { fontSize: 12, color: '#5C636C', marginTop: 12 },
});