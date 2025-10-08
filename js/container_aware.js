// Fetch all images containing a "data-srcset" attribute.
const images = document.querySelectorAll('img[data-srcset]');

// Create a ResizeObserver to update the image "src" attribute when its
// parent container resizes.
const observer = new ResizeObserver(entries => {
  for (let entry of entries) {
    const images = entry.target.querySelectorAll('img[data-srcset]');
    images.forEach(image => {
      const maxWidth = Math.floor(image.parentNode.clientWidth);
      const maxHeight = Math.floor(image.parentNode.clientHeight);
      const maxDensity = window.devicePixelRatio || 1;

      if (!image.getAttribute('data-srcset')) return false;

      const sources = image.getAttribute('data-srcset').split(',');

      // Select source.
      for (var i = 0; i < sources.length; i++) {
        // The following regular expression was created based on the rules
        // in the srcset W3C specification available at:
        // http://www.w3.org/html/wg/drafts/srcset/w3c-srcset/

        var descriptors = sources[i].match(
              /^\s*([^\s]+)\s*(\s(\d+)w)?\s*(\s(\d+)h)?\s*(\s(\d+)x)?\s*$/
          ),
          filename = descriptors[1],
          width    = descriptors[3] || false,
          height   = descriptors[5] || false,
          density  = descriptors[7] || 1;

        if (width && width < maxWidth) {
          continue;
        }

        if (height && height < maxHeight) {
          continue;
        }

        if (density && density > maxDensity) {
          continue;
        }

        // Set image source.
        image.setAttribute('src', filename);
        break;
      }
    });
  }
});

// Attach the ResizeObserver to the image containers.
images.forEach(image => {
  observer.observe(image.parentNode);
});
