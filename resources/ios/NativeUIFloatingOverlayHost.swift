import SwiftUI
import UIKit

/// Wraps the rendered screen tree in a floating overlay when the published tree
/// carries a `floating_overlay` element. Unlike a bottom bar it does NOT inset
/// the content — the overlay floats on a top layer (a `ZStack`), so the screen
/// beneath is untouched and the pill hovers above it (and above the tab bar).
///
/// The overlay content is **arbitrary** — its children render through the
/// generic `NodeView`, so developers can float any Blade/elements (a pill, a
/// banner, a mini-player).
///
/// Placement (from the element's props):
///   - `alignment` — `bottom` (above the tab bar, default) or `top`
///     (below the nav bar).
///   - `offset`    — extra points between the overlay and the aligned edge on
///     top of the safe-area inset; unset → a default that clears a standard
///     bottom tab bar.
///
/// When `overlayNode` is nil this is a transparent pass-through, so it's safe
/// to wrap every tree unconditionally.
struct NativeFloatingOverlayHost<Content: View>: View {
    let overlayNode: NativeUINode?
    @ViewBuilder var content: Content

    /// Default clearance above the aligned edge (on top of the safe-area
    /// inset) when the layout doesn't set an explicit `offset`. Sized to clear
    /// a standard iOS tab bar with a small margin.
    private let defaultBottomClearance: CGFloat = 60
    private let defaultTopClearance: CGFloat = 8

    /// Real safe-area insets read straight from the window. The wrapped content
    /// manages its own safe area (via the environment, like NativeTreeRenderer),
    /// so a nested reader here double-counts — position against the window inset
    /// instead, same approach as NativeDrawerHost.
    private var windowInsets: UIEdgeInsets {
        UIApplication.shared.connectedScenes
            .compactMap { $0 as? UIWindowScene }
            .first?.windows.first?.safeAreaInsets ?? .zero
    }

    var body: some View {
        if let overlayNode {
            let isTop = overlayNode.props.getString("alignment", default: "bottom") == "top"
            // getInt returns 0 for an absent key; the builder only ever emits a
            // positive offset, so 0 means "unset" → use the default clearance.
            let rawOffset = CGFloat(overlayNode.props.getInt("offset", default: 0))

            ZStack(alignment: isTop ? .top : .bottom) {
                content

                overlayView(overlayNode)
                    .padding(isTop ? .top : .bottom,
                             edgeInset(isTop: isTop) + (rawOffset > 0 ? rawOffset : defaultClearance(isTop: isTop)))
                    .frame(maxWidth: .infinity, maxHeight: .infinity,
                           alignment: isTop ? .top : .bottom)
                    .allowsHitTesting(true)
            }
            .ignoresSafeArea()
        } else {
            content
        }
    }

    private func edgeInset(isTop: Bool) -> CGFloat {
        isTop ? windowInsets.top : windowInsets.bottom
    }

    private func defaultClearance(isTop: Bool) -> CGFloat {
        isTop ? defaultTopClearance : defaultBottomClearance
    }

    /// The floating content itself. Children render through the generic
    /// `NodeView`, and the overlay wrapper sizes to its content (a pill), so
    /// only the pill — not the full-width layer — captures taps.
    @ViewBuilder
    private func overlayView(_ overlayNode: NativeUINode) -> some View {
        VStack(spacing: 0) {
            ForEach(overlayNode.children) { child in
                NodeView(node: child).equatable()
            }
        }
        .fixedSize(horizontal: false, vertical: true)
    }
}
