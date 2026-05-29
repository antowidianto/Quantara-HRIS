import React from 'react';
import {
  ActivityIndicator,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  TouchableOpacity,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { useTheme } from '@theme/ThemeProvider';
import { NotificationsApi, type NotificationItem } from './api';

const fmtTime = (iso: string): string => {
  const d = new Date(iso);
  const now = Date.now();
  const diff = now - d.getTime();
  if (diff < 60_000) return 'baru saja';
  if (diff < 3_600_000) return `${Math.floor(diff / 60_000)} menit lalu`;
  if (diff < 86_400_000) return `${Math.floor(diff / 3_600_000)} jam lalu`;
  return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short' });
};

const CATEGORY_ICONS: Record<string, string> = {
  approval:   '🔔',
  leave:      '🌴',
  attendance: '🕒',
  payroll:    '💰',
  training:   '🎓',
  helpdesk:   '🎫',
  default:    '📩',
};

/**
 * In-app notification list — Tier 2.5.
 * Backend `NotificationController::index()` sudah ada.
 * Push notif via FCM dispatched terpisah (DeviceController + PushNotifier).
 */
export const NotificationsScreen: React.FC = () => {
  const theme = useTheme();
  const queryClient = useQueryClient();

  const listQ = useQuery({
    queryKey: ['notifications.list'],
    queryFn: NotificationsApi.list,
    staleTime: 30_000,
  });

  const markReadMut = useMutation({
    mutationFn: (id: number) => NotificationsApi.markRead(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['notifications.list'] });
      queryClient.invalidateQueries({ queryKey: ['notifications.unread'] });
    },
  });

  const markAllMut = useMutation({
    mutationFn: () => NotificationsApi.markAllRead(),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['notifications.list'] });
      queryClient.invalidateQueries({ queryKey: ['notifications.unread'] });
    },
  });

  const c = theme.colors;
  const items = listQ.data ?? [];
  const unreadCount = items.filter((i) => !i.is_read).length;

  return (
    <SafeAreaView style={[styles.root, { backgroundColor: c.bg }]}>
      <ScrollView
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={listQ.isRefetching} onRefresh={listQ.refetch} />}
      >
        <View style={styles.headerRow}>
          <View>
            <Text style={[styles.h1, { color: c.text }]}>Notifikasi</Text>
            {unreadCount > 0 && (
              <Text style={[styles.muted, { color: c.textMuted }]}>
                {unreadCount} belum dibaca
              </Text>
            )}
          </View>
          {unreadCount > 0 && (
            <TouchableOpacity
              onPress={() => markAllMut.mutate()}
              style={styles.linkBtn}
            >
              <Text style={{ color: c.primary, fontSize: 13, fontWeight: '600' }}>
                Tandai semua
              </Text>
            </TouchableOpacity>
          )}
        </View>

        {listQ.isLoading && <ActivityIndicator color={c.primary} style={{ marginTop: 24 }} />}

        {!listQ.isLoading && items.length === 0 && (
          <View style={styles.emptyBox}>
            <Text style={{ fontSize: 36 }}>📭</Text>
            <Text style={[styles.emptyTitle, { color: c.text }]}>Tidak ada notifikasi</Text>
            <Text style={[styles.muted, { color: c.textMuted, textAlign: 'center' }]}>
              Notifikasi baru akan muncul di sini.
            </Text>
          </View>
        )}

        {items.map((n) => (
          <NotificationRow
            key={n.id}
            item={n}
            colors={c}
            onPress={() => !n.is_read && markReadMut.mutate(n.id)}
          />
        ))}
      </ScrollView>
    </SafeAreaView>
  );
};

const NotificationRow: React.FC<{
  item: NotificationItem;
  colors: ReturnType<typeof useTheme>['colors'];
  onPress: () => void;
}> = ({ item, colors: c, onPress }) => {
  const icon = CATEGORY_ICONS[item.category] ?? CATEGORY_ICONS.default;
  return (
    <TouchableOpacity
      onPress={onPress}
      style={[
        styles.row,
        {
          backgroundColor: item.is_read ? c.surface : c.surfaceAlt,
          borderColor: c.border,
        },
      ]}
    >
      <Text style={styles.icon}>{icon}</Text>
      <View style={{ flex: 1 }}>
        <Text style={[styles.title, { color: c.text, fontWeight: item.is_read ? '500' : '700' }]} numberOfLines={1}>
          {item.title}
        </Text>
        <Text style={[styles.body, { color: c.textMuted }]} numberOfLines={2}>
          {item.body}
        </Text>
        <Text style={[styles.timeText, { color: c.textMuted }]}>{fmtTime(item.created_at)}</Text>
      </View>
      {!item.is_read && <View style={[styles.dot, { backgroundColor: c.primary }]} />}
    </TouchableOpacity>
  );
};

const styles = StyleSheet.create({
  root: { flex: 1 },
  content: { padding: 16, paddingBottom: 40 },
  headerRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start' },
  h1: { fontSize: 24, fontWeight: '700' },
  muted: { fontSize: 13, marginTop: 4 },
  linkBtn: { paddingVertical: 8, paddingHorizontal: 4 },
  emptyBox: { paddingVertical: 60, alignItems: 'center' },
  emptyTitle: { fontSize: 18, fontWeight: '600', marginTop: 12, marginBottom: 4 },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    marginTop: 8,
    padding: 12,
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 8,
  },
  icon: { fontSize: 22, marginRight: 12 },
  title: { fontSize: 15, marginBottom: 2 },
  body: { fontSize: 13 },
  timeText: { fontSize: 11, marginTop: 4 },
  dot: { width: 8, height: 8, borderRadius: 4, marginLeft: 8 },
});