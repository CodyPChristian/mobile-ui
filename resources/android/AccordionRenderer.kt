package com.nativephp.plugins.native_ui.ui

import androidx.compose.runtime.Composable
import com.nativephp.mobile.ui.nativerender.NativeUINode
import com.nativephp.mobile.ui.nativerender.NodeView
import com.nativephp.plugins.native_ui.NativeUITheme
import androidx.compose.runtime.remember
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.Modifier
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.setValue
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.size
import androidx.compose.material3.Text
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.padding
import androidx.compose.animation.animateContentSize
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.material3.IconButton
import androidx.compose.material3.Icon
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.KeyboardArrowDown
import androidx.compose.ui.draw.rotate

object AccordionRenderer {
    @Composable
    fun Render(node: NativeUINode, modifier: Modifier) {
        var expanded by remember { mutableStateOf(node.props.getBool("expanded")) }
        val rotationAngle by animateFloatAsState(
            targetValue = if (expanded) 180f else 0f,
            label = "Chevron Rotation"
        )

        Column(
            modifier = Modifier
                .fillMaxWidth()
                .animateContentSize().then(modifier)
        ) {
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .clickable(
                        interactionSource = remember { MutableInteractionSource() },
                        indication = null
                    ) { expanded = !expanded },
                horizontalArrangement = Arrangement.SpaceBetween
            ) {
                node.children.forEach { child ->
                    if (child.type == "accordion_header") {
                        child.children.forEach { child1 -> NodeView(node = child1) }
                    }
                }

                IconButton(modifier = Modifier.then(Modifier.size(24.dp)), onClick = { expanded = !expanded }) {
                    Icon(
                        imageVector = Icons.Default.KeyboardArrowDown,
                        contentDescription = null,
                        modifier = Modifier.rotate(rotationAngle),
                    )
                }
            }

            AnimatedVisibility(visible = expanded) {
                node.children.forEach { child ->
                    if (child.type == "accordion_content") {
                        child.children.forEach { child1 -> NodeView(node = child1) }
                    }
                }
            }
        }
    }
}