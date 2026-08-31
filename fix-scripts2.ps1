$files = Get-ChildItem -Path "." -Filter "*.html" | Select-Object -ExpandProperty Name
$fixed = 0

# Known variant endings for the dropdown toggle block - matches either variable name (link or a)
# The block always ends with: parent.classList.toggle('open');\n    }\n  });\n});\n
$dropdownPatterns = @(
    # Unindented version
    "document.querySelectorAll('.nav-dropdown > a').forEach(function(link) {`n  link.addEventListener('click', function(e) {`n    if (window.innerWidth <= 768) {`n      e.preventDefault();`n      var parent = this.parentElement;`n      parent.classList.toggle('open');`n    }`n  });`n});",
    "document.querySelectorAll('.nav-dropdown > a').forEach(function(a) {`n  a.addEventListener('click', function(e) {`n    if (window.innerWidth <= 768) {`n      e.preventDefault();`n      var parent = a.parentElement;`n      parent.classList.toggle('open');`n    }`n  });`n});",
    # Indented 4 spaces version
    "    document.querySelectorAll('.nav-dropdown > a').forEach(function(link) {`n      link.addEventListener('click', function(e) {`n        if (window.innerWidth <= 768) {`n          e.preventDefault();`n          var parent = this.parentElement;`n          parent.classList.toggle('open');`n        }`n      });`n    });",
    "    document.querySelectorAll('.nav-dropdown > a').forEach(function(a) {`n      a.addEventListener('click', function(e) {`n        if (window.innerWidth <= 768) {`n          e.preventDefault();`n          var parent = a.parentElement;`n          parent.classList.toggle('open');`n        }`n      });`n    });"
)

foreach ($f in $files) {
    $content = [IO.File]::ReadAllText($f)
    $original = $content
    $changes = @()

    # Remove dropdown toggle blocks (exact match, all variants)
    foreach ($pattern in $dropdownPatterns) {
        if ($content.Contains($pattern)) {
            $content = $content.Replace($pattern, '')
            $changes += "removed dropdown toggle"
            break
        }
    }

    # Also remove via regex for any remaining indentation variants
    $before = $content
    $content = [regex]::Replace($content,
        "(?m)^[ \t]*document\.querySelectorAll\('\.nav-dropdown > a'\)\.forEach\(function\(\w+\) \{`n(?:[ \t]+[^\n]*`n)+?[ \t]*\}\);\s*`n?",
        '')
    if ($content -ne $before -and $changes -notcontains "removed dropdown toggle") {
        $changes += "removed dropdown toggle (regex)"
    }

    # Remove the comment line left behind: // Mobile nav dropdown toggles
    $before = $content
    $content = $content.Replace("// Mobile nav dropdown toggles`n", "")
    if ($content -ne $before) { $changes += "removed comment" }

    # Remove stray }); at the start of a script block (after only whitespace/newlines)
    $before = $content
    $content = [regex]::Replace($content, '(<script>)\r?\n\}\);\r?\n', '$1' + "`n")
    if ($content -ne $before) { $changes += "removed stray });" }

    # Clean up empty <script> blocks left over
    $before = $content
    $content = [regex]::Replace($content, '<script>\s*</script>\s*\r?\n?', '')
    if ($content -ne $before) { $changes += "removed empty script block" }

    if ($content -ne $original) {
        [IO.File]::WriteAllText($f, $content)
        $fixed++
        Write-Host "Fixed $f`: $($changes -join ', ')"
    } else {
        Write-Host "No change: $f"
    }
}

Write-Host ""
Write-Host "Done. Fixed: $fixed files"
