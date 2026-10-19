{{-- The people a lead can be given to, as a menu that opens beside whatever asked for it. Place it inside an
     element with x-data="assignMenu({ people, teams, assign, send })" and open it with openFor($el, { lead, dept, owner }).
     It is drawn at the end of <body>: a table's scroll box or a slide-over would cut it otherwise. --}}
<template x-teleport="body">
    <div class="lq-menu lq-assign" x-ref="menu" x-show="open" x-cloak x-bind:style="place" x-on:click.outside="close()" x-on:keydown.escape.window="open && close()" x-on:resize.window="close()" role="dialog" aria-label="Assign this lead">
        <div class="lq-picker-search" x-show="inReach.length > 6">
            <x-heroicon-o-magnifying-glass />
            <input type="text" x-ref="search" x-model="query" placeholder="Search a person…" aria-label="Search a person" autocomplete="off" spellcheck="false">
        </div>

        <p class="lq-menu-header" x-text="owner === null ? 'Assign to' : 'Give to someone else'"></p>

        <ul class="lq-assign-list">
            <template x-for="person in shown" :key="person.id">
                <li>
                    <button type="button" class="lq-menu-item lq-assign-person" x-on:click="pick(person)" x-bind:disabled="person.id === owner">
                        <span class="lq-avatar lq-avatar-sm" aria-hidden="true">
                            <template x-if="person.picture"><img x-bind:src="person.picture" alt=""></template>
                            <template x-if="! person.picture"><span x-text="initials(person.name)"></span></template>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="lq-cell-name" x-text="person.me ? person.name + ' (you)' : person.name"></span>
                            <span class="lq-cell-sub" x-show="person.team !== ''" x-text="person.team"></span>
                        </span>
                        <x-heroicon-m-check class="lq-assign-tick" x-show="person.id === owner" />
                    </button>
                </li>
            </template>
        </ul>

        <p class="lq-picker-note" x-show="shown.length === 0" x-text="query.trim() !== '' ? 'Nobody matches.' : (dept === null ? 'No Agents in this brand yet. Add them under Members.' : 'Nobody works in this lead\'s department yet.')"></p>

        <template x-if="teams.length > 0">
            <div>
                <div class="lq-menu-divider"></div>
                <p class="lq-menu-header" x-text="dept === null ? 'Or send to a team' : 'Or move to a team'"></p>
                <template x-for="team in teams" :key="team.id">
                    <button type="button" class="lq-menu-item" x-on:click="sendTo(team)" x-bind:disabled="team.id === dept">
                        <span class="lq-team-dot" x-bind:style="'background: ' + team.color"></span>
                        <span class="min-w-0 flex-1 truncate" x-text="team.name"></span>
                        <x-heroicon-m-check class="lq-assign-tick" x-show="team.id === dept" />
                    </button>
                </template>
            </div>
        </template>
    </div>
</template>
