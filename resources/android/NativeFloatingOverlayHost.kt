package com.nativephp.plugins.native_ui.ui

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.nativephp.mobile.ui.nativerender.NativeUINode
import com.nativephp.mobile.ui.nativerender.RenderNode

/**
 * Hosts the content-agnostic floating overlay (`floating_overlay`). Unlike a
 * bottom bar it does NOT inset the content — the overlay floats on a top layer
 * (a [Box]), so the screen beneath is untouched and the pill hovers above it
 * (and above the tab bar).
 *
 * Registered on core's `NativeRootHostRegistry` from this plugin's init
 * function ([registerNativeUIChrome]); core folds it around the rendered tree.
 * The overlay content is arbitrary — its children render through the generic
 * [RenderNode]. When `overlayNode` is null this is a transparent pass-through.
 *
 * Placement (from the element's props):
 *   - `alignment` → `bottom` (above the tab bar, default) or `top`.
 *   - `offset`    → extra dp between the overlay and the aligned edge on top of
 *     the system-bar inset; unset (0) → a default that clears a tab bar.
 */
@Composable
fun NativeFloatingOverlayHost(
    overlayNode: NativeUINode?,
    content: @Composable () -> Unit,
) {
    if (overlayNode == null) {
        content()
        return
    }

    val isTop = overlayNode.props.getString("alignment", "bottom") == "top"
    // getInt returns 0 for an absent key; the builder only ever emits a
    // positive offset, so 0 means "unset" → use the default clearance.
    val rawOffset = overlayNode.props.getInt("offset", 0)
    val clearance = (if (rawOffset > 0) rawOffset else if (isTop) DEFAULT_TOP_CLEARANCE else DEFAULT_BOTTOM_CLEARANCE).dp

    Box(Modifier.fillMaxSize()) {
        content()

        // The overlay sizes to its content (a pill), so only the pill — not the
        // full layer — sits in the layout / captures taps.
        val overlayModifier = Modifier
            .align(if (isTop) Alignment.TopCenter else Alignment.BottomCenter)
            .then(if (isTop) Modifier.statusBarsPadding() else Modifier.navigationBarsPadding())
            .padding(if (isTop) PaddingTop(clearance) else PaddingBottom(clearance))

        Box(overlayModifier) {
            overlayNode.children.forEach { child ->
                RenderNode(child)
            }
        }
    }
}

private const val DEFAULT_BOTTOM_CLEARANCE = 72
private const val DEFAULT_TOP_CLEARANCE = 8

private fun PaddingBottom(value: androidx.compose.ui.unit.Dp) =
    androidx.compose.foundation.layout.PaddingValues(bottom = value)

private fun PaddingTop(value: androidx.compose.ui.unit.Dp) =
    androidx.compose.foundation.layout.PaddingValues(top = value)
