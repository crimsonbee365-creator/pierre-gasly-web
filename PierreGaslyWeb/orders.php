package com.pierregasly.app.ui.main

import android.content.Intent
import android.os.Bundle
import android.view.View
import android.view.animation.AnimationUtils
import android.widget.ImageView
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import coil.load
import coil.transform.RoundedCornersTransformation
import com.pierregasly.app.R
import com.pierregasly.app.data.model.supabase.ProductRow
import com.pierregasly.app.data.repository.AppDataRepository
import com.pierregasly.app.data.repository.Result
import com.pierregasly.app.ui.auth.LoginActivity
import com.pierregasly.app.ui.common.MenuHelper
import com.pierregasly.app.ui.common.ThemePrefs
import com.pierregasly.app.utils.CartManager
import com.pierregasly.app.utils.ProductImageHelper
import com.pierregasly.app.utils.SessionManager
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

class DashboardActivity : AppCompatActivity() {

    private val session by lazy { SessionManager(this) }
    private val dataRepo by lazy { AppDataRepository() }

    override fun onCreate(savedInstanceState: Bundle?) {
        ThemePrefs.applySavedTheme(this)
        super.onCreate(savedInstanceState)

        try {
            if (!session.isLoggedIn()) {
                startActivity(Intent(this, LoginActivity::class.java))
                finish()
                return
            }

            setContentView(R.layout.activity_dashboard)
            MainNavHelper.setup(this, activeTab = 0)
            
            findViewById<View>(R.id.btnViewAllProducts).setOnClickListener {
                startActivity(Intent(this, ProductsActivity::class.java))
            }
            findViewById<View>(R.id.btnOpenRewards).setOnClickListener {
                startActivity(Intent(this, RewardsActivity::class.java))
            }
            findViewById<View>(R.id.cardRewardsSnapshot).setOnClickListener {
                startActivity(Intent(this, RewardsActivity::class.java))
            }

            findViewById<View>(R.id.btnMenu).setOnClickListener { MenuHelper.show(it, this) }
            findViewById<View>(R.id.btnCart).apply {
                visibility = View.VISIBLE
                setOnClickListener { startActivity(Intent(this@DashboardActivity, CheckoutActivity::class.java)) }
            }
            findViewById<TextView>(R.id.tvPageTitle).text = "Dashboard"

            val email = session.getUserEmail().orEmpty()
            val name = session.getUserName().orEmpty().ifBlank { 
                email.substringBefore('@').replaceFirstChar { it.uppercase() } 
            }

            findViewById<TextView>(R.id.tvGreeting).text = "Welcome back, $name!"
            findViewById<TextView>(R.id.tvEmail).text = if (email.isBlank()) "-" else email

            animateViews()
            loadDashboardData()
        } catch (e: Exception) {
            e.printStackTrace()
            Toast.makeText(this, "Dashboard UI loaded with limited data.", Toast.LENGTH_SHORT).show()
        }
    }

    private fun animateViews() {
        val fadeIn = AnimationUtils.loadAnimation(this, android.R.anim.fade_in)
        val slideUp = AnimationUtils.loadAnimation(this, android.R.anim.slide_in_left)
        
        findViewById<View>(R.id.tvGreeting)?.startAnimation(fadeIn)
        
        lifecycleScope.launch {
            delay(100)
            findViewById<View>(R.id.tvRewardTier)?.parent?.let { 
                (it as? View)?.startAnimation(slideUp)
            }
            delay(100)
            findViewById<View>(R.id.tvRewardPoints)?.parent?.let { 
                (it as? View)?.startAnimation(slideUp)
            }
        }
    }

    private fun loadDashboardData() {
        val token = session.getAccessToken().orEmpty()
        val email = session.getUserEmail().orEmpty()
        if (token.isBlank()) return

        lifecycleScope.launch {
            when (val rewardsRes = dataRepo.getRewardsSummary(token, email, session.getAuthUserId())) {
                is Result.Success -> {
                    val tier = rewardsRes.data.tier.ifBlank { "Bronze" }
                    val points = rewardsRes.data.pointsBalance
                    findViewById<TextView>(R.id.tvRewardTier).text = tier
                    findViewById<TextView>(R.id.tvRewardPoints).text = "$points pts"
                    findViewById<TextView>(R.id.tvRewardTierBonus).text = "Tier bonus: +${tierBonusPercent(tier)}%"
                    findViewById<TextView>(R.id.tvRewardPesoValue).text = "≈ ₱${"%.2f".format((points / 500.0) * 50.0)} value"
                    applyRewardCardBackground(tier)

                    findViewById<View>(R.id.tvRewardTier)?.let { view ->
                        val pulse = AnimationUtils.loadAnimation(this@DashboardActivity, android.R.anim.fade_in)
                        view.startAnimation(pulse)
                    }
                }
                is Result.Error -> {
                    findViewById<TextView>(R.id.tvRewardTier).text = "Bronze"
                    findViewById<TextView>(R.id.tvRewardPoints).text = "0 pts"
                    findViewById<TextView>(R.id.tvRewardTierBonus).text = "Tier bonus: +0%"
                    findViewById<TextView>(R.id.tvRewardPesoValue).text = "≈ ₱0.00 value"
                    applyRewardCardBackground("Bronze")
                }
                else -> Unit
            }

            when (val productsRes = dataRepo.getTopProducts(token, 3)) {
                is Result.Success -> {
                    if (productsRes.data.isNotEmpty()) {
                        renderTopProducts(productsRes.data)
                    } else {
                        showEmptyProducts()
                    }
                }
                else -> showEmptyProducts()
            }
        }
    }


    private fun applyRewardCardBackground(tier: String) {
        val backgroundRes = when (tier.trim().lowercase()) {
            "silver" -> R.drawable.bg_credit_silver
            "gold" -> R.drawable.bg_credit_gold
            "platinum" -> R.drawable.bg_credit_platinum
            else -> R.drawable.bg_credit_bronze
        }
        findViewById<View>(R.id.cardRewardTierBackground)?.setBackgroundResource(backgroundRes)
        findViewById<View>(R.id.cardRewardPointsBackground)?.setBackgroundResource(backgroundRes)
    }

    private fun showEmptyProducts() {
        val names = listOf(
            findViewById<TextView>(R.id.tvTopName1),
            findViewById<TextView>(R.id.tvTopName2),
            findViewById<TextView>(R.id.tvTopName3)
        )
        val variants = listOf(
            findViewById<TextView>(R.id.tvTopVariant1),
            findViewById<TextView>(R.id.tvTopVariant2),
            findViewById<TextView>(R.id.tvTopVariant3)
        )
        val prices = listOf(
            findViewById<TextView>(R.id.tvTopPrice1),
            findViewById<TextView>(R.id.tvTopPrice2),
            findViewById<TextView>(R.id.tvTopPrice3)
        )
        
        names.forEach { it.text = "No products yet" }
        variants.forEach { it.text = "Add products to see them here" }
        prices.forEach { it.text = "-" }

        val cards = listOf(
            findViewById<View>(R.id.cardTopProduct1),
            findViewById<View>(R.id.cardTopProduct2),
            findViewById<View>(R.id.cardTopProduct3)
        )
        cards.forEach { card ->
            card.isClickable = true
            card.setOnClickListener {
                startActivity(Intent(this, ProductsActivity::class.java))
            }
        }
    }

    private fun renderTopProducts(products: List<ProductRow>) {
        val names = listOf(
            findViewById<TextView>(R.id.tvTopName1),
            findViewById<TextView>(R.id.tvTopName2),
            findViewById<TextView>(R.id.tvTopName3)
        )
        val variants = listOf(
            findViewById<TextView>(R.id.tvTopVariant1),
            findViewById<TextView>(R.id.tvTopVariant2),
            findViewById<TextView>(R.id.tvTopVariant3)
        )
        val prices = listOf(
            findViewById<TextView>(R.id.tvTopPrice1),
            findViewById<TextView>(R.id.tvTopPrice2),
            findViewById<TextView>(R.id.tvTopPrice3)
        )
        val images = listOf(
            findViewById<ImageView>(R.id.ivTopProduct1),
            findViewById<ImageView>(R.id.ivTopProduct2),
            findViewById<ImageView>(R.id.ivTopProduct3)
        )
        val fallbacks = listOf(
            findViewById<ImageView>(R.id.ivTopProduct1Fallback),
            findViewById<ImageView>(R.id.ivTopProduct2Fallback),
            findViewById<ImageView>(R.id.ivTopProduct3Fallback)
        )
        val stockBadges = listOf(
            findViewById<TextView>(R.id.tvTopStock1),
            findViewById<TextView>(R.id.tvTopStock2),
            findViewById<TextView>(R.id.tvTopStock3)
        )

        for (i in 0..2) {
            val p = products.getOrNull(i)
            names[i].text = p?.productName ?: "No product yet"
            variants[i].text = p?.let { prod -> val stock = prod.stockQuantity ?: 0; val size = prod.sizeKg?.let { "${it}kg variant" } ?: "Variant"; if (stock > 0) "$size • $stock in stock" else "$size • Awaiting stock" } ?: "Awaiting stock"
            prices[i].text = p?.price?.let { "₱${"%.2f".format(it)}" } ?: "-"
            
            // Show stock badge
            val stock = p?.stockQuantity ?: 0
            if (stock > 0) {
                stockBadges[i].visibility = View.VISIBLE
                stockBadges[i].text = "In Stock"
            } else {
                stockBadges[i].visibility = View.GONE
            }

            val imageUrl = ProductImageHelper.resolve(p?.productImage)
            if (imageUrl.isNullOrBlank()) {
                images[i].visibility = View.GONE
                fallbacks[i].visibility = View.VISIBLE
            } else {
                images[i].visibility = View.VISIBLE
                fallbacks[i].visibility = View.GONE
                images[i].load(imageUrl) {
                    crossfade(true)
                    transformations(RoundedCornersTransformation(18f))
                    error(R.drawable.ic_lpg_cylinder)
                    fallback(R.drawable.ic_lpg_cylinder)
                    listener(
                        onSuccess = { _, _ ->
                            images[i].alpha = 0f
                            images[i].animate().alpha(1f).setDuration(300).start()
                        }
                    )
                }
            }

            findViewById<View>(when (i) {
                0 -> R.id.cardTopProduct1
                1 -> R.id.cardTopProduct2
                else -> R.id.cardTopProduct3
            }).setOnClickListener {
                if (p != null) {
                    showTopProductActions(p)
                } else {
                    startActivity(Intent(this, ProductsActivity::class.java))
                }
            }

            lifecycleScope.launch {
                delay(i * 100L)
                names[i].parent?.parent?.let { card ->
                    (card as? View)?.let { view ->
                        view.alpha = 0f
                        view.translationY = 50f
                        view.animate()
                            .alpha(1f)
                            .translationY(0f)
                            .setDuration(300)
                            .start()
                    }
                }
            }
        }
    }


    private fun showTopProductActions(product: ProductRow) {
        val dialogView = layoutInflater.inflate(R.layout.dialog_product_details, null)
        val imageView = dialogView.findViewById<ImageView>(R.id.ivDialogProductImage)
        val fallbackView = dialogView.findViewById<ImageView>(R.id.ivDialogProductFallback)
        val imageUrl = ProductImageHelper.resolve(product.productImage)
        if (!imageUrl.isNullOrBlank()) {
            imageView.visibility = View.VISIBLE
            fallbackView.visibility = View.GONE
            imageView.scaleType = ImageView.ScaleType.FIT_CENTER
            imageView.load(imageUrl) {
                crossfade(true)
                error(R.drawable.ic_lpg_cylinder)
                fallback(R.drawable.ic_lpg_cylinder)
            }
        }

        dialogView.findViewById<TextView>(R.id.tvDialogProductName).text = product.productName
        dialogView.findViewById<TextView>(R.id.tvDialogProductVariant).text =
            product.sizeKg?.let { "${it}kg LPG cylinder" } ?: "Standard LPG cylinder"
        dialogView.findViewById<TextView>(R.id.tvDialogProductPrice).text =
            product.price?.let { "₱${"%.2f".format(it)}" } ?: "-"

        val stock = product.stockQuantity ?: 0
        dialogView.findViewById<TextView>(R.id.tvDialogProductStock).apply {
            text = if (stock > 0) "In stock: $stock" else "Out of stock"
            setBackgroundResource(if (stock > 0) R.drawable.bg_status_verified else R.drawable.bg_button_outline_error)
            setTextColor(if (stock > 0) getColor(android.R.color.white) else getColor(R.color.error))
        }

        dialogView.findViewById<TextView>(R.id.tvDialogProductDescription).text =
            product.description?.takeIf { it.isNotBlank() }
                ?: "No extra description available yet for this item."

        val dialogBuilder = AlertDialog.Builder(this)
            .setView(dialogView)
            .setNegativeButton("Close", null)

        if (stock > 0) {
            dialogBuilder
                .setPositiveButton("Order Now") { _, _ ->
                    CartManager.addItem(product, 1)
                    startActivity(Intent(this, CheckoutActivity::class.java))
                }
                .setNeutralButton("Add to Cart") { _, _ ->
                    CartManager.addItem(product, 1)
                    Toast.makeText(this, "${product.productName} added to cart", Toast.LENGTH_SHORT).show()
                }
        }

        dialogBuilder.show()
    }

    private fun tierBonusPercent(tier: String): Int = when (tier.lowercase()) {
        "silver" -> 10
        "gold" -> 20
        "platinum" -> 30
        else -> 0
    }
}
