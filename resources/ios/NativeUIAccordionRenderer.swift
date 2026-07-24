import SwiftUI

struct NativeUIAccordionRenderer: View {
    let node: NativeUINode
    @State private var isExpanded: Bool = false
    @Environment(\.colorScheme) private var colorScheme

    var body: some View {
        DisclosureGroup(
            isExpanded: $isExpanded,
            content: {
                ForEach(node.children) { child in
                    if child.type == "accordion_content" {
                        ForEach(child.children) { child1 in
                            NodeView(node: child1)
                                .equatable()
                        }
                        
                    }
                }
            },
            label: {
                ForEach(node.children) { child in
                    if child.type == "accordion_header" {
                        ForEach(child.children) { child1 in
                            NodeView(node: child1)
                                .equatable()
                        }
                    }
                }
            }
        )
        .onAppear {
            isExpanded = node.props.getBool("expanded")
        }
    }
}
