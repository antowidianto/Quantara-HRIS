import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/auth/session_controller.dart';
import '../../core/notifications/push_service.dart';
import '../../core/offline/outbox.dart';
import '../approvals/approvals_screen.dart';
import '../attendance/attendance_screen.dart';
import '../leave/leave_screen.dart';
import '../more/more_screen.dart';
import '../payslip/payslip_screen.dart';

class HomeShell extends ConsumerStatefulWidget {
  const HomeShell({super.key});

  @override
  ConsumerState<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends ConsumerState<HomeShell>
    with WidgetsBindingObserver {
  var _selected = 0;
  StreamSubscription<List<ConnectivityResult>>? _connectivitySubscription;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _connectivitySubscription = Connectivity().onConnectivityChanged.listen((
      results,
    ) {
      if (results.any((result) => result != ConnectivityResult.none)) {
        unawaited(ref.read(outboxProvider).sweep());
      }
    });
    unawaited(ref.read(outboxProvider).sweep());
    unawaited(ref.read(pushServiceProvider).initializeAndSync());
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      unawaited(ref.read(outboxProvider).sweep());
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _connectivitySubscription?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final user = ref.watch(sessionProvider).user;
    final destinations = <NavigationDestination>[
      const NavigationDestination(
        icon: Icon(Icons.schedule_outlined),
        selectedIcon: Icon(Icons.schedule),
        label: 'Presensi',
      ),
      const NavigationDestination(
        icon: Icon(Icons.beach_access_outlined),
        selectedIcon: Icon(Icons.beach_access),
        label: 'Cuti',
      ),
      if ((user?.roleId ?? 0) != 0)
        const NavigationDestination(
          icon: Icon(Icons.approval_outlined),
          selectedIcon: Icon(Icons.approval),
          label: 'Approval',
        ),
      const NavigationDestination(
        icon: Icon(Icons.payments_outlined),
        selectedIcon: Icon(Icons.payments),
        label: 'Slip',
      ),
      const NavigationDestination(
        icon: Icon(Icons.apps_outlined),
        selectedIcon: Icon(Icons.apps),
        label: 'Lainnya',
      ),
    ];
    final pages = <Widget>[
      const AttendanceScreen(),
      const LeaveScreen(),
      if ((user?.roleId ?? 0) != 0) const ApprovalsScreen(),
      const PayslipScreen(),
      const MoreScreen(),
    ];
    final safeIndex = _selected.clamp(0, pages.length - 1);
    return Scaffold(
      body: SafeArea(
        child: IndexedStack(index: safeIndex, children: pages),
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: safeIndex,
        destinations: destinations,
        onDestinationSelected: (index) => setState(() => _selected = index),
      ),
    );
  }
}
