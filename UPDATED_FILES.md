# Updated and added files

## Application setup

1. `lib/main.dart` — system bar styling and Flutter entry point.
2. `lib/app/app.dart` — app theme, initial onboarding route, and route generator.
3. `lib/app/routes/app_routes.dart` — onboarding, login, signup, verification,
   dashboard, settings, and privacy routes.

## Shared theme and widgets

4. `lib/core/theme/app_colors.dart` — complete ScanWell color palette.
5. `lib/core/theme/app_theme.dart` — global Material and form styling.
6. `lib/core/widgets/app_bottom_navigation_bar.dart` — reusable responsive
   bottom navigation.
7. `lib/core/widgets/app_card.dart` — reusable bordered card.
8. `lib/core/widgets/app_text_field.dart` — reusable labeled field.
9. `lib/core/widgets/auth_card.dart` — authentication card container.
10. `lib/core/widgets/primary_button.dart` — green primary button with loading.
11. `lib/core/widgets/security_note.dart` — security information row.
12. `lib/core/widgets/segmented_auth_selector.dart` — password/OTP selector.

## Onboarding

13. `lib/features/onboarding/data/onboarding_page_data.dart` — ordered data for
    onboarding screens 1, 2, and 3.
14. `lib/features/onboarding/presentation/onboarding_screen.dart` — preserves
    the `1 of 3`, `2 of 3`, and `3 of 3` sequence and links to auth screens.

## Dummy authentication

15. `lib/features/auth/data/dummy_auth_service.dart` — lightweight in-memory
    authentication for frontend testing.
16. `lib/features/auth/presentation/login_screen.dart` — password and OTP login,
    validation, loading state, and dashboard navigation.
17. `lib/features/auth/presentation/signup_screen.dart` — signup form and email
    verification navigation.
18. `lib/features/auth/presentation/verify_email_screen.dart` — six-digit OTP,
    auto-focus, resend action, and dashboard navigation. Demo code: `123456`.

## Contributor dashboard

19. `lib/features/dashboard/data/submission.dart` — submission model, statuses,
    and dummy recent submissions.
20. `lib/features/dashboard/presentation/contributor_dashboard_screen.dart` —
    contributor header, statistics, quick actions, impact banner, recent
    submissions, and contributor navigation.

## Settings and privacy

21. `lib/features/settings/widgets/consumer_bottom_navigation.dart` — bottom
    navigation used by Settings and Privacy.
22. `lib/features/settings/presentation/settings_screen.dart` — profile card,
    settings menu, privacy link, and security note.
23. `lib/features/settings/presentation/privacy_screen.dart` — saved-data cards,
    health concern chips, data actions, and privacy information.

## Assets and documentation

24. `assets/images/impact_trophy.png` — dashboard impact artwork.
25. `assets/images/product_lays.png`
26. `assets/images/product_quaker.png`
27. `assets/images/product_coke.png`
28. `assets/images/product_maggi.png`
29. `assets/images/product_amul.png`
30. `assets/images/profile_aminul.png` — settings profile image.
31. `README.md` — run instructions, sequence, and dummy test credentials.
32. `test/widget_test.dart` — confirms onboarding begins from screen 1.

## Responsive device support update

33. `lib/core/layout/responsive_layout.dart` — shared compact-phone and tablet
    breakpoints, adaptive page padding, and centered maximum-width content.
34. Authentication and onboarding screens now remain scrollable in short or
    landscape viewports, use compact padding on narrow phones, and stay centered
    instead of stretching on tablets.
35. The OTP inputs calculate their width from the actual card width so all six
    fields remain visible on compact screens.
36. Dashboard header, impact banner, submission rows, Settings, Privacy, and the
    shared bottom navigation adapt safely to narrow phones, landscape devices,
    tablets, Safe Areas, and larger text.
37. `test/responsive_screens_test.dart` — responsive overflow smoke tests at
    320x568, 360x640, 390x844, 844x390, and 800x1280.

## Product Scan and Product Search

### New files
- `lib/features/consumer/data/consumer_product.dart`
- `lib/features/consumer/data/demo_consumer_data.dart`
- `lib/features/consumer/presentation/scan_screen.dart`
- `lib/features/consumer/presentation/product_search_screen.dart`
- `lib/features/consumer/widgets/consumer_bottom_navigation.dart`
- `lib/features/consumer/widgets/health_flag_badge.dart`
- Product image assets prefixed with `product_home_` and `product_search_`
- `TWO_PAGES_UPDATE.md`

### Updated files
- `lib/app/routes/app_routes.dart`
- `lib/features/auth/presentation/login_screen.dart`
- `lib/features/auth/presentation/verify_email_screen.dart`
- `lib/features/settings/presentation/settings_screen.dart`
- `lib/features/settings/presentation/privacy_screen.dart`
- `lib/features/settings/widgets/consumer_bottom_navigation.dart`
- `test/responsive_screens_test.dart`

Successful dummy login and verification open the original contributor dashboard as Home. The product scanner is a separate Scan screen, and Product Search remains separate.


## Dashboard/Home restoration merge

- Compared the dashboard-first project with the later scan/search project.
- Restored `ContributorDashboardScreen` as `/home` without removing `/dashboard`.
- Renamed the scanner UI from `HomeScreen` to `ScanScreen`.
- Added `/scan` and connected the Scan tab.
- Kept `/product-search` as a separate route.
- Updated login and verification flow to reach the dashboard through `/home`.
- Updated dashboard Add Product navigation, consumer bottom navigation, and responsive tests.
