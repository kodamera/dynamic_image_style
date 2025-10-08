# Dynamic Image Style
This module enables developers to use dynamically generated image styles (based
on given settings) in twig templates.

## Installation
1. Add the repository to the project's composer.json:
```
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/kodamera/dynamic_image_style"
        }
    ]
}
```
2. Install the module `$ composer require kodamera/dynamic_image_style`
3. Enable the module `$ drush en dynamic_image_style`

## Settings syntax
Settings use a single letter suffix for each type of setting.
The letter is preceded by the config for the specific setting.

* `w` - specifies width in pixels, preceded by a number
      * Example: `500w`
* `h` - specifies height in pixels, preceded by a number
      * Example: `200h`
* `r` - specifies an aspect ratio, preceded by aspect ratio using WxH syntax
      * Requires a specified width or height
      * Example: `200h_16x9r`
* `x` - specifies a multiplier, preceded by a number used to multiply width and hights (including calculated ones)
      * Example: `500w_2x`

All generated image styles also converts the image to WebP format.

## Container aware image sources
The module includes a script to handle images sources based on the size of the
containing class (instead of window width). It uses the uses the `data-srcset`
attribute and use the same syntax as the `srcset` attribute.

## Twig filter
The module provides multiple Twig filters for generating dynamic image styles.
See the exampels section below on how to use them.

## Examples

There are three ways of generating image styles in Twig templates.

### Generate srcset strings (recommended)

Use the `dynamic_image_style_source` filter when using a `<picture>` element.
The filter will return a `srcset` string with the provided settings. It supports
specifying DPI multipliers. Omitting multiplier will default to adding a 2x
variant. 1x is always added.

The filter takes a file ID, a settings string and an optional multiplier array
as input.

Note that the `<img src="">` fallback uses the `dynamic_image_style_url` filter,
since it should only be a URL and not a `srcset` string.

```html
{% set file = paragraph.field_media.entity.field_media_image.entity.id() %}

{% set sources = {
  "1536px": file|dynamic_image_style_source('16x9r_1536w', [2, 3]),
  "1280px": file|dynamic_image_style_source('16x9r_1280w', [3, 4]),
  "1024px": file|dynamic_image_style_source('16x9r_1024w'),
  "768px": file|dynamic_image_style_source('16x9r_768w'),
  "0px": file|dynamic_image_style_source('16x9r_320w'),
} %}
<picture>
  {% for key, source in sources %}
    <source srcset="{{ source }}" media="all and (min-width: {{ key }})"
            type="image/jpeg">
  {% endfor %}
  <img src="{{ file|dynamic_image_style_url('16x9r_50w') }}"
       alt="{{ alt_text }}"/>
</picture>
```

This will generate the following markup:

```html
<picture>
  <source srcset="/dynamic-image-style/123/16x9r_1536w 1x, /dynamic-image-style/123/16x9r_1536w_2x 2x, /dynamic-image-style/123/16x9r_1536w_3x 3x" media="all and (min-width: 1536px)" type="image/jpeg">
  <source srcset="/dynamic-image-style/123/16x9r_1280w 1x, /dynamic-image-style/123/16x9r_1280w_3x 3x, /dynamic-image-style/123/16x9r_1280w_4x 4x" media="all and (min-width: 1280px)" type="image/jpeg">
  <source srcset="/dynamic-image-style/123/16x9r_1024w 1x, /dynamic-image-style/123/16x9r_1024w_2x 2x" media="all and (min-width: 1024px)" type="image/jpeg">
  <source srcset="/dynamic-image-style/123/16x9r_768w 1x, /dynamic-image-style/123/16x9r_768w_2x 2x" media="all and (min-width: 768px)" type="image/jpeg">
  <source srcset="/dynamic-image-style/123/16x9r_320w 1x, /dynamic-image-style/123/16x9r_320w_2x 2x" media="all and (min-width: 0px)" type="image/jpeg">
  <img src="/dynamic-image-style/123/16x9r_50w" alt="En bild på en himmel" class="w-full">
</picture>
```

### Generate URLs

Use the `dynamic_image_style_url` filter for the `src` attribute of an `<img>`
element, or when building a fully custom `<picture>` element. The filter takes a
file ID and a settings string as input.

```html
{# Getting the file ID can look different depending on the entity structure. #}
{% set file = paragraph.field_media.entity.field_media_image.entity.id() %}

<img
    src="{{ file|dynamic_image_style_url('16x9r_150w') }}"
    alt="En bild på en himmel">
```

```html
{# Getting the file ID can look different depending on the entity structure. #}
{% set file = paragraph.field_media.entity.field_media_image.entity.id() %}
<picture>
  <source
    srcset="{{ file|dynamic_image_style_url('16x9r_1536w') }} 1x, {{ file|dynamic_image_style_url('16x9r_3072w') }} 2x"
    media="all and (min-width: 1536px)" type="image/jpeg">
  <source
    srcset="{{ file|dynamic_image_style_url('16x9r_1280w') }} 1x, {{ file|dynamic_image_style_url('16x9r_2560w') }} 2x"
    media="all and (min-width: 1280px)" type="image/jpeg">
  <source
    srcset="{{ file|dynamic_image_style_url('16x9r_1024w') }} 1x, {{ file|dynamic_image_style_url('16x9r_2048w') }} 2x"
    media="all and (min-width: 1024px)" type="image/jpeg">
  <source
    srcset="{{ file|dynamic_image_style_url('16x9r_768w') }} 1x, {{ file|dynamic_image_style_url('16x9r_1536w') }} 2x"
    media="all and (min-width: 768px)" type="image/jpeg">
  <source
    srcset="{{ file|dynamic_image_style_url('16x9r_320w') }} 1x, {{ file|dynamic_image_style_url('16x9r_640w') }} 2x"
    media="all and (min-width: 0px)" type="image/jpeg">
  <img
    src="{{ file|dynamic_image_style_url('16x9r_50w') }}"
    alt="{{ alt_text }}"/>
</picture>
```

### Generate URL and create derivative at compile time

Use the `dynamic_image_style` filter when getting a URL and wanting the image to be
generated at the same time. The filter takes a URI and a settings string as
input.

This approach generates all images when the Twig template is compiled. This can
cause performance issues, so use with caution.

```
{#
/**
 * @file
 * Default theme implementation to display an image.
 */
#}
{% set file = media.field_media_image.entity %}
{% set src = file.uri.value|dynamic_image_style('16x9r_50w') %}
{% set srcset = [
  file.uri.value|dynamic_image_style('16x9r_150w') ~ ' 150w',
  file.uri.value|dynamic_image_style('16x9r_350w') ~ ' 350w',
  file.uri.value|dynamic_image_style('16x9r_550w') ~ ' 550w',
  file.uri.value|dynamic_image_style('16x9r_950w') ~ ' 950w',
  file.uri.value|dynamic_image_style('16x9r_1250w') ~ ' 1250w',
  file.uri.value|dynamic_image_style('16x9r_1450w') ~ ' 1450w',
] %}
<img src="{{ src }}" data-srcset="{{ srcset|join(',') }}" alt="{{ media.field_media_image.alt }}" loading="lazy" />
```
