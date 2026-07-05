import SwiftUI

struct NativeUICarouselRenderer: View {
    let node: NativeUINode

    var body: some View {
        let p = node.props
        let itemWidth = CGFloat(p.getFloat("item_width").let { $0 > 0 ? $0 : 200 })
        let itemSpacing = CGFloat(p.getFloat("item_spacing").let { $0 > 0 ? $0 : 8 })
        let a11yLabel = p.getString("a11y_label")

        ScrollView(.horizontal, showsIndicators: false) {
            LazyHStack(spacing: itemSpacing) {
                ForEach(node.children) { child in
                    RenderNode(node: child)
                        .frame(width: itemWidth)
                        .clipShape(RoundedRectangle(cornerRadius: 16))
                }
            }
            .padding(.horizontal, 16)
        }
        .modifier(A11yLabelModifier(label: a11yLabel))
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

private extension Float {
    func `let`(_ transform: (Float) -> Float) -> Float {
        return transform(self)
    }
}
