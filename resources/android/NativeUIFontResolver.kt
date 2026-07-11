package com.nativephp.plugins.native_ui.ui

import android.content.Context
import android.content.res.AssetManager
import androidx.compose.ui.text.font.Font
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.unit.TextUnit
import androidx.compose.ui.unit.sp

// Tailwind `leading`: line_height_px is an absolute sp target; line_height is a
// unitless multiplier of the font size. Unspecified leaves Compose's default.
// Shared by the text / button / text-input renderers.
internal fun nuiLineHeightUnit(px: Float, mult: Float, fontSize: Float): TextUnit = when {
    px > 0f -> px.sp
    mult > 0f -> (mult * fontSize).sp
    else -> TextUnit.Unspecified
}

/**
 * Resolves a custom-font token (a font file's basename, e.g. "Inter-Bold") to a
 * Compose [FontFamily] loaded from the app's `assets/fonts/`. Fonts land there
 * via this plugin's `copy_assets` hook (CopyFontsCommand).
 *
 * Results — including "no such font" — are cached, so repeated recompositions
 * don't re-hit the AssetManager. A token that resolves to null lets callers
 * fall back to the default family.
 */
object NativeUIFontResolver {

    // token -> FontFamily; a stored null means "looked up, not present".
    private val cache = HashMap<String, FontFamily?>()

    private val extensions = listOf("ttf", "otf", "ttc")

    @Synchronized
    fun resolve(context: Context, token: String): FontFamily? {
        if (cache.containsKey(token)) {
            return cache[token]
        }

        val family = build(context.assets, token)
        cache[token] = family

        return family
    }

    private fun build(assets: AssetManager, token: String): FontFamily? {
        for (ext in extensions) {
            val path = "fonts/$token.$ext"
            if (!assetExists(assets, path)) {
                continue
            }

            return try {
                FontFamily(Font(path = path, assetManager = assets))
            } catch (e: Exception) {
                null
            }
        }

        return null
    }

    private fun assetExists(assets: AssetManager, path: String): Boolean {
        return try {
            assets.open(path).close()
            true
        } catch (e: Exception) {
            false
        }
    }
}
