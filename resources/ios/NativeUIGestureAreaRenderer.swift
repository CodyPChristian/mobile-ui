import SwiftUI

/// Captures a vertical drag and writes the cumulative translation into
/// the bound `SharedValue` on `SharedValueStore`. Children render
/// normally — the gesture wraps the whole content frame.
///
/// Driven by props:
///   - `pan-y-id`       (int) — id of the SharedValue receiving updates.
///   - `pan-y-initial`  (float) — value to seed the store with on first
///                                appearance, so child elements
///                                bound through formulas have something
///                                to evaluate against before the user
///                                touches the screen.
///
/// On gesture end, the drag start anchor is reset so subsequent drags
/// continue from the current value rather than snapping to 0. PHP-side
/// `@drag-end` callback wiring is a follow-up — for now the value
/// stays where the user left it and PHP can read it on the next event
/// from elsewhere.
struct NativeUIGestureAreaRenderer: View {
    let node: NativeUINode

    @State private var dragStart: CGFloat = 0
    @ObservedObject private var store = SharedValueStore.shared

    var body: some View {
        let panYId = node.props.getInt("pan-y-id", default: 0)
        let panYInitial = CGFloat(node.props.getFloat("pan-y-initial", default: 0))
        let a11yLabel = node.props.getString("a11y_label")
        let a11yHint = node.props.getString("a11y_hint")

        VStack(spacing: 0) {
            ForEach(node.children) { child in
                NodeView(node: child).equatable()
            }
        }
        .contentShape(Rectangle())
        .gesture(
            DragGesture(minimumDistance: 0)
                .onChanged { value in
                    guard panYId != 0 else { return }
                    store.set(dragStart + value.translation.height, for: panYId)
                }
                .onEnded { _ in
                    guard panYId != 0 else { return }
                    dragStart = store.value(for: panYId)
                }
        )
        .onAppear {
            if panYId != 0 && store.values[panYId] == nil {
                store.seed(panYInitial, for: panYId)
            }
        }
        .modifier(A11yLabelModifier(label: a11yLabel))
        .modifier(A11yHintModifier(hint: a11yHint))
    }
}

// MARK: - Accessibility modifiers (conditional)

private struct A11yLabelModifier: ViewModifier {
    let label: String
    func body(content: Content) -> some View {
        if label.isEmpty { content }
        else { content.accessibilityLabel(label) }
    }
}

private struct A11yHintModifier: ViewModifier {
    let hint: String
    func body(content: Content) -> some View {
        if hint.isEmpty { content }
        else { content.accessibilityHint(hint) }
    }
}
