{{-- Sidebar accordion: only the active navigation group is expanded, and expanding a group collapses the others.
     Each group's "Reports" item collapses independently. --}}
<script>
    (() => {
        const storageKey = 'filament:sidebar:collapsed_groups'

        const mainGroups = () => Array.from(document.querySelectorAll('.fi-main-sidebar .fi-sidebar-group.fi-collapsible'))
            .filter((group) => group.dataset.groupLabel)

        const mainLabels = () => mainGroups().map((group) => group.dataset.groupLabel)

        // Collapse every group except the active one. Runs before Alpine boots, so Alpine's persisted store
        // picks this state up and there is no flash of expanded groups.
        const collapseInactiveGroups = () => {
            const collapsed = []

            mainGroups().forEach((group) => {
                const items = group.querySelector('.fi-sidebar-group-items')
                const isActive = group.classList.contains('fi-active')

                if (! isActive) {
                    collapsed.push(group.dataset.groupLabel)
                }

                group.classList.toggle('fi-collapsed', ! isActive)

                if (items) {
                    items.style.display = isActive ? '' : 'none'
                }
            })

            return collapsed
        }

        // "Reports" parent items: collapsed unless one of their report pages is the current page.
        const reportItemButtons = () => document.querySelectorAll('.fi-main-sidebar .fi-sidebar-reports-item > .fi-sidebar-item-btn')

        const setReportsExpanded = (button, expanded) => {
            button.parentElement.classList.toggle('fi-reports-collapsed', ! expanded)
            button.setAttribute('aria-expanded', expanded ? 'true' : 'false')
        }

        const initReportItems = () => reportItemButtons().forEach((button) => {
            button.setAttribute('role', 'button')
            button.setAttribute('tabindex', '0')
            setReportsExpanded(button, button.parentElement.classList.contains('fi-sidebar-item-has-active-child-items'))
        })

        const toggleReports = (event) => {
            const button = event.target.closest('.fi-sidebar-reports-item > .fi-sidebar-item-btn')

            if (! button || (event.type === 'keydown' && ! ['Enter', ' '].includes(event.key))) {
                return
            }

            // Capture phase: stop the item's own handler, which closes the sidebar on mobile.
            event.preventDefault()
            event.stopPropagation()

            setReportsExpanded(button, button.parentElement.classList.contains('fi-reports-collapsed'))
        }

        document.addEventListener('click', toggleReports, true)
        document.addEventListener('keydown', toggleReports, true)

        initReportItems()

        localStorage.setItem(storageKey, JSON.stringify(collapseInactiveGroups()))

        document.addEventListener('alpine:initialized', () => {
            const store = window.Alpine.store('sidebar')

            store.toggleCollapsedGroup = function (group) {
                const labels = mainLabels()

                // Keep the collapse state of groups outside the main sidebar (e.g. sub-navigation) untouched.
                const others = this.collapsedGroups.filter((label) => ! labels.includes(label))

                this.collapsedGroups = this.collapsedGroups.includes(group)
                    ? others.concat(labels.filter((label) => label !== group))
                    : others.concat(labels)
            }
        })

        document.addEventListener('livewire:navigated', () => {
            initReportItems()

            const store = window.Alpine?.store('sidebar')

            if (store) {
                store.collapsedGroups = collapseInactiveGroups()
            }
        })
    })()
</script>
